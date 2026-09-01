<?php

namespace App\Console\Commands;

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Models\Business;
use App\Models\CommissionLedgerEntry;
use App\Models\DailyStatsSummary;
use App\Models\HourlyConfirmationStat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\Scopes\BusinessScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Full recalculation of the pre-computed dashboard stats from source-of-
 * truth tables (orders, order_status_events, commission_ledger_entries) —
 * the PRD section 5 "correctness safety net" behind the near-real-time
 * queued listeners. Replays the same facts the listeners record, with the
 * same conventions: rows are keyed by the ORDER's creation date (matching
 * IncrementsDailyStats), hourly buckets by the EVENT's own hour (matching
 * RecordHourlyConfirmationStat), is_test orders excluded unconditionally
 * (UC-25), and returned_count driven by return_received, never
 * returned_in_transit.
 *
 * Wipes and rebuilds daily_stats_summary and hourly_confirmation_stats
 * per business. daily_stats_reasons is not rebuilt here — its writer
 * records reason codes at transition time and the audit log carries no
 * reason column to replay from.
 */
class RebuildDailyStatsCommand extends Command
{
    protected $signature = 'stats:rebuild {--business= : Rebuild a single business id}';

    protected $description = 'Recalculate daily_stats_summary and hourly_confirmation_stats from orders, status events, and the commission ledger';

    /** @var array<string, array<string, mixed>> */
    private array $summaryRows = [];

    /** @var array<string, array<string, mixed>> */
    private array $hourlyRows = [];

    public function handle(): int
    {
        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->pluck('id');

        foreach ($businesses as $businessId) {
            $this->rebuildBusiness((int) $businessId);
        }

        $this->info(sprintf('Rebuilt stats for %d business(es).', $businesses->count()));

        return self::SUCCESS;
    }

    private function rebuildBusiness(int $businessId): void
    {
        $this->summaryRows = [];
        $this->hourlyRows = [];

        Order::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('is_test', false)
            ->orderBy('id')
            ->chunkById(200, function (Collection $orders) {
                $orderIds = $orders->pluck('id');

                $events = OrderStatusEvent::withoutGlobalScope(BusinessScope::class)
                    ->whereIn('order_id', $orderIds)
                    ->orderBy('created_at')
                    ->get()
                    ->groupBy('order_id');

                $productIds = OrderItem::withoutGlobalScope(BusinessScope::class)
                    ->whereIn('order_id', $orderIds)
                    ->whereNotNull('product_id')
                    ->get(['order_id', 'product_id'])
                    ->groupBy('order_id')
                    ->map(fn (Collection $items) => $items->pluck('product_id')->unique()->values());

                $commissions = CommissionLedgerEntry::withoutGlobalScope(BusinessScope::class)
                    ->whereIn('order_id', $orderIds)
                    ->where('entry_type', 'earned')
                    ->get(['order_id', 'amount'])
                    ->groupBy('order_id')
                    ->map(fn (Collection $entries) => (float) $entries->sum('amount'));

                foreach ($orders as $order) {
                    $this->replayOrder(
                        $order,
                        $events->get($order->id) ?? collect(),
                        $productIds->get($order->id) ?? collect(),
                        $commissions->get($order->id, 0.0),
                    );
                }
            });

        DB::transaction(function () use ($businessId) {
            DailyStatsSummary::withoutGlobalScope(BusinessScope::class)->where('business_id', $businessId)->delete();
            HourlyConfirmationStat::withoutGlobalScope(BusinessScope::class)->where('business_id', $businessId)->delete();

            foreach (array_chunk($this->summaryRows, 500) as $chunk) {
                DailyStatsSummary::insert($chunk);
            }

            foreach (array_chunk($this->hourlyRows, 500) as $chunk) {
                HourlyConfirmationStat::insert($chunk);
            }
        });
    }

    /**
     * @param  Collection<int, OrderStatusEvent>  $events
     * @param  Collection<int, int>  $productIds
     */
    private function replayOrder(Order $order, Collection $events, Collection $productIds, float $commission): void
    {
        $scopes = $this->scopesFor($order, $productIds);
        $statDate = $order->created_at->toDateString();

        $bump = function (string $column, float|int $amount = 1) use ($order, $scopes, $statDate) {
            foreach ($scopes as $scope) {
                $this->bumpSummary($order->business_id, $statDate, $scope, $column, $amount);
            }
        };

        // Mirrors RecordOrderCreatedStat: every (non-test) order counts once.
        $bump('orders_count');

        if ($commission > 0) {
            $bump('commission_total', $commission);
        }

        $lastAssignedAt = null;

        foreach ($events as $event) {
            $eventAt = Carbon::parse($event->created_at);

            match ($event->to_status) {
                OrderConfirmationStatus::ASSIGNED->value => $bump('assigned_count'),
                OrderConfirmationStatus::SUBMITTED_TO_COURIER->value => $bump('submitted_to_courier_count'),
                OrderConfirmationStatus::CANCELLED->value => $bump('cancelled_count'),
                OrderDeliveryStatus::RETURN_RECEIVED->value => $bump('returned_count'),
                OrderDeliveryStatus::REFUSED->value => $bump('refused_count'),
                default => null,
            };

            if ($event->to_status === OrderConfirmationStatus::ASSIGNED->value) {
                $lastAssignedAt = $eventAt;
            }

            if ($event->to_status === OrderConfirmationStatus::CONFIRMED->value) {
                $bump('confirmed_count');
                $bump('revenue_confirmed', (float) $order->total_amount);

                if ($lastAssignedAt !== null) {
                    $bump('confirm_seconds_total', (int) $lastAssignedAt->diffInSeconds($eventAt));
                }
            }

            if ($event->to_status === OrderDeliveryStatus::DELIVERED->value) {
                $bump('delivered_count');
                $bump('revenue_delivered', (float) $order->total_amount);

                if ($order->shipped_at !== null) {
                    $bump('delivery_seconds_total', (int) $order->shipped_at->diffInSeconds($eventAt));
                }
            }

            $this->bumpHourly($order, $event->to_status, $eventAt);
        }
    }

    /**
     * Same fan-out as IncrementsDailyStats::dailyStatScopes().
     *
     * @param  Collection<int, int>  $productIds
     * @return list<array{store_id: int|null, agent_id: int|null, product_id: int|null, delivery_account_id: int|null}>
     */
    private function scopesFor(Order $order, Collection $productIds): array
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

        foreach ($productIds as $productId) {
            $scopes[] = ['product_id' => $productId] + $blank;
        }

        return $scopes;
    }

    /**
     * @param  array{store_id: int|null, agent_id: int|null, product_id: int|null, delivery_account_id: int|null}  $scope
     */
    private function bumpSummary(int $businessId, string $statDate, array $scope, string $column, float|int $amount): void
    {
        $key = implode('|', [
            $businessId,
            $statDate,
            $scope['store_id'] ?? 0,
            $scope['product_id'] ?? 0,
            $scope['agent_id'] ?? 0,
            $scope['delivery_account_id'] ?? 0,
        ]);

        if (! isset($this->summaryRows[$key])) {
            $this->summaryRows[$key] = [
                'business_id' => $businessId,
                'stat_date' => $statDate,
                ...$scope,
                'orders_count' => 0,
                'assigned_count' => 0,
                'confirmed_count' => 0,
                'submitted_to_courier_count' => 0,
                'delivered_count' => 0,
                'returned_count' => 0,
                'cancelled_count' => 0,
                'refused_count' => 0,
                'confirmation_rate' => null,
                'delivery_success_rate' => null,
                'revenue_confirmed' => 0,
                'revenue_delivered' => 0,
                'commission_total' => 0,
                'confirm_seconds_total' => 0,
                'delivery_seconds_total' => 0,
            ];
        }

        $row = &$this->summaryRows[$key];
        $row[$column] += $amount;

        if (in_array($column, ['revenue_confirmed', 'revenue_delivered', 'commission_total'], true)) {
            $row[$column] = round($row[$column], 2);
        }

        $row['confirmation_rate'] = $row['orders_count'] > 0
            ? round(($row['confirmed_count'] / $row['orders_count']) * 100, 2)
            : null;
        $row['delivery_success_rate'] = $row['submitted_to_courier_count'] > 0
            ? round(($row['delivered_count'] / $row['submitted_to_courier_count']) * 100, 2)
            : null;
    }

    private function bumpHourly(Order $order, string $toStatus, Carbon $eventAt): void
    {
        $attemptStatuses = [
            OrderConfirmationStatus::CONFIRMED->value,
            OrderConfirmationStatus::CONFIRMED_FOLLOWUP->value,
            OrderConfirmationStatus::CALLBACK->value,
            OrderConfirmationStatus::FAKE->value,
            OrderConfirmationStatus::VOICEMAIL->value,
            OrderConfirmationStatus::NO_ANSWER->value,
            OrderConfirmationStatus::BUSY->value,
            OrderConfirmationStatus::WHATSAPP_SENT->value,
        ];

        if (! in_array($toStatus, $attemptStatuses, true)) {
            return;
        }

        $confirmed = $toStatus === OrderConfirmationStatus::CONFIRMED->value;

        $agentScopes = $order->assigned_agent_id !== null
            ? [null, $order->assigned_agent_id]
            : [null];

        foreach ($agentScopes as $agentId) {
            $key = implode('|', [$order->business_id, $agentId ?? 0, $eventAt->toDateString(), $eventAt->hour]);

            if (! isset($this->hourlyRows[$key])) {
                $this->hourlyRows[$key] = [
                    'business_id' => $order->business_id,
                    'agent_id' => $agentId,
                    'stat_date' => $eventAt->toDateString(),
                    'hour' => $eventAt->hour,
                    'attempts_count' => 0,
                    'confirmed_count' => 0,
                ];
            }

            $this->hourlyRows[$key]['attempts_count']++;

            if ($confirmed) {
                $this->hourlyRows[$key]['confirmed_count']++;
            }
        }
    }
}
