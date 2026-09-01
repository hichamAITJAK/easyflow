<?php

namespace App\Listeners\Order\Concerns;

use App\Models\DailyStatsReason;
use App\Models\DailyStatsSummary;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Scopes\BusinessScope;
use App\Models\Store;
use Illuminate\Database\QueryException;

/**
 * Shared "bump a daily_stats_summary figure for this order" logic, used
 * by every listener that updates live stats on an order lifecycle event
 * (PRD section 5: dashboards read only from this pre-computed table).
 *
 * Every write fans out to the row for each scope the order belongs to:
 * the business-wide row, plus store-, agent-, courier- and per-product-
 * scoped rows when applicable — so dashboards filtered by any dimension
 * never re-aggregate from the business-wide row.
 */
trait IncrementsDailyStats
{
    /** Bump a count column by one across all of the order's stat scopes. */
    private function incrementDailyStat(Order $order, string $column): void
    {
        $this->applyToDailyStatRows($order, function (DailyStatsSummary $row) use ($column) {
            $row->increment($column);
            $this->refreshDerivedRates($row, $column);
        });
    }

    /**
     * Add a money amount (e.g. the order's total_amount on delivery) to a
     * decimal column across all of the order's stat scopes.
     */
    private function incrementDailyStatMoney(Order $order, string $column, float $amount): void
    {
        $this->applyToDailyStatRows($order, function (DailyStatsSummary $row) use ($column, $amount) {
            $row->increment($column, round($amount, 2));
        });
    }

    /**
     * Add a duration (seconds) to a *_seconds_total column across all of
     * the order's stat scopes. Averages derive at read time as
     * sum ÷ the matching count column.
     */
    private function incrementDailyStatSeconds(Order $order, string $column, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $this->applyToDailyStatRows($order, function (DailyStatsSummary $row) use ($column, $seconds) {
            $row->increment($column, $seconds);
        });
    }

    /**
     * Resolve every stat row the order contributes to and run the mutation
     * on each: business-wide, store, agent, courier (delivery account),
     * and one row per distinct product on the order.
     *
     * @param  callable(DailyStatsSummary): void  $mutate
     */
    private function applyToDailyStatRows(Order $order, callable $mutate): void
    {
        if ($order->is_test) {
            return;
        }

        $statDate = $order->created_at->toDateString();

        foreach ($this->dailyStatScopes($order) as $scope) {
            $mutate($this->resolveDailyStatRow($order->business_id, $statDate, $scope));
        }
    }

    /**
     * @return list<array{store_id: int|null, agent_id: int|null, product_id: int|null, delivery_account_id: int|null}>
     */
    private function dailyStatScopes(Order $order): array
    {
        $blank = ['store_id' => null, 'agent_id' => null, 'product_id' => null, 'delivery_account_id' => null];

        $scopes = [$blank];

        if ($order->store_id !== null) {
            $scopes[] = ['store_id' => $order->store_id] + $blank;
        }

        if ($order->assigned_agent_id !== null) {
            $scopes[] = ['agent_id' => $order->assigned_agent_id] + $blank;
        }

        if ($order->delivery_account_id !== null) {
            $scopes[] = ['delivery_account_id' => $order->delivery_account_id] + $blank;
        }

        $productIds = OrderItem::where('order_id', $order->id)
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id');

        foreach ($productIds as $productId) {
            $scopes[] = ['product_id' => $productId] + $blank;
        }

        return $scopes;
    }

    /**
     * @param  array{store_id: int|null, agent_id: int|null, product_id: int|null, delivery_account_id: int|null}  $scope
     */
    private function resolveDailyStatRow(int $businessId, string $statDate, array $scope): DailyStatsSummary
    {
        // stat_date is a `date`-cast column, stored with a midnight
        // timestamp — a plain string-equality match (what firstOrCreate()
        // builds) never matches the stored value, so the row is found via
        // an explicit whereDate() query instead.
        $find = fn () => DailyStatsSummary::where('business_id', $businessId)
            ->whereDate('stat_date', $statDate)
            ->where('store_id', $scope['store_id'])
            ->where('agent_id', $scope['agent_id'])
            ->where('product_id', $scope['product_id'])
            ->where('delivery_account_id', $scope['delivery_account_id'])
            ->first();

        $row = $find();

        if ($row !== null) {
            return $row;
        }

        try {
            // Money/duration columns start at 0, not their NULL default —
            // increment() compiles to `SET col = col + ?`, and in SQL
            // NULL + anything stays NULL, so a NULL-born row would
            // silently swallow every later amount.
            return DailyStatsSummary::create([
                'business_id' => $businessId,
                'stat_date' => $statDate,
                ...$scope,
                // Snapshot, not a join: store_id has no foreign key so these
                // rows outlive the store, and the dashboard would otherwise
                // drop a deleted store's history from the per-store
                // breakdown while still counting it in the totals.
                // withoutGlobalScope + explicit business_id: this runs from a
                // queued listener where there is no authenticated user, so
                // BusinessScope would silently no-op rather than scope.
                'store_name' => $scope['store_id'] === null
                    ? null
                    : Store::withoutGlobalScope(BusinessScope::class)
                        ->where('business_id', $businessId)
                        ->whereKey($scope['store_id'])
                        ->value('name'),
                'revenue_confirmed' => 0,
                'revenue_delivered' => 0,
                'commission_total' => 0,
                'confirm_seconds_total' => 0,
                'delivery_seconds_total' => 0,
            ]);
        } catch (QueryException $exception) {
            // The scope-unique index turned a concurrent-writer race into
            // a duplicate-key error: another queued job created this row
            // between our find and create. Re-fetch and use theirs.
            $row = $find();

            if ($row === null) {
                throw $exception;
            }

            return $row;
        }
    }

    /**
     * confirmation_rate/delivery_success_rate are read (not just
     * displayed) by UC-22's performance check, so they're kept in sync
     * on every increment rather than left for a report to derive from
     * the raw counts each time — same source of truth, computed once.
     */
    private function refreshDerivedRates(DailyStatsSummary $row, string $column): void
    {
        if (in_array($column, ['orders_count', 'confirmed_count'], true)) {
            $row->update([
                'confirmation_rate' => $row->orders_count > 0
                    ? round(($row->confirmed_count / $row->orders_count) * 100, 2)
                    : null,
            ]);
        }

        if (in_array($column, ['submitted_to_courier_count', 'delivered_count'], true)) {
            $row->update([
                'delivery_success_rate' => $row->submitted_to_courier_count > 0
                    ? round(($row->delivered_count / $row->submitted_to_courier_count) * 100, 2)
                    : null,
            ]);
        }
    }

    /**
     * Bump a daily_stats_reasons row for the given order's cancellation or
     * return reason code — same business/store/agent scoping as the
     * summary writes, but against the reasons table since the reason-code
     * space doesn't fit fixed daily_stats_summary columns.
     */
    private function incrementDailyStatReason(Order $order, string $reasonType, string $reasonCode): void
    {
        if ($order->is_test) {
            return;
        }

        $statDate = $order->created_at->toDateString();

        $this->incrementDailyStatReasonRow($order->business_id, $statDate, ['store_id' => null, 'agent_id' => null], $reasonType, $reasonCode);

        if ($order->store_id !== null) {
            $this->incrementDailyStatReasonRow($order->business_id, $statDate, ['store_id' => $order->store_id, 'agent_id' => null], $reasonType, $reasonCode);
        }

        if ($order->assigned_agent_id !== null) {
            $this->incrementDailyStatReasonRow($order->business_id, $statDate, ['store_id' => null, 'agent_id' => $order->assigned_agent_id], $reasonType, $reasonCode);
        }
    }

    /**
     * @param  array{store_id: int|null, agent_id: int|null}  $scope
     */
    private function incrementDailyStatReasonRow(int $businessId, string $statDate, array $scope, string $reasonType, string $reasonCode): void
    {
        // Same whereDate() fix as resolveDailyStatRow() — stat_date is a
        // `date`-cast column, so a raw string match in firstOrCreate()
        // never finds the existing row.
        $row = DailyStatsReason::where('business_id', $businessId)
            ->whereDate('stat_date', $statDate)
            ->where('store_id', $scope['store_id'])
            ->where('agent_id', $scope['agent_id'])
            ->where('reason_type', $reasonType)
            ->where('reason_code', $reasonCode)
            ->first() ?? DailyStatsReason::create([
                'business_id' => $businessId,
                'stat_date' => $statDate,
                'store_id' => $scope['store_id'],
                'agent_id' => $scope['agent_id'],
                'reason_type' => $reasonType,
                'reason_code' => $reasonCode,
            ]);

        $row->increment('count');
    }
}
