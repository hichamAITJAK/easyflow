<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\OrderConfirmationStatus;
use App\Enums\PerformanceMetric;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CourierSettlement;
use App\Models\DailyStatsSummary;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Models\PerformanceTarget;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const PERIOD_DAYS = 30;

    /** Stale-transit alert threshold (PRD 7.3's safety-net visibility). */
    private const STALE_TRANSIT_DAYS = 5;

    /**
     * Longest custom range the dashboard will honour. Every per-day widget
     * builds one point per day in the window, so an unbounded range would
     * let a hand-edited URL ask for years of points in one payload.
     */
    private const MAX_CUSTOM_RANGE_DAYS = 366;

    /**
     * Render the role-appropriate dashboard. Admins/super admins see a
     * business-wide view; confirmation agents see only their own stats.
     * Fulfilment agents get the same personal view for now (they have no
     * assigned_agent_id activity yet — their real dashboard is the not
     * -yet-built mobile fulfilment app).
     *
     * All series are read from the pre-computed stats tables
     * (daily_stats_summary, hourly_confirmation_stats), never aggregated
     * live from orders, per the PRD's dashboard non-functional
     * requirement. The two deliberate exceptions are stock snapshots, not
     * flows: the confirmed-pipeline value and the alert counts, both
     * cheap indexed queries on current state.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        if (in_array($user->role, [UserRole::SUPER_ADMIN, UserRole::ADMIN], true)) {
            return $this->adminDashboard($user, $request);
        }

        return $this->agentDashboard($user);
    }

    private function adminDashboard(User $user, Request $request): Response
    {
        $businessId = $user->business_id;

        $storeIds = array_values(
            collect(explode(',', (string) $request->string('store_ids')))
                ->filter(fn (string $id) => ctype_digit($id))
                ->map(fn (string $id) => (int) $id)
                ->all(),
        );

        $period = $request->string('period')->toString() ?: '30d';
        [$since, $until] = $this->resolvePeriod($period, $request);
        $windowDays = (int) $since->diffInDays($until) + 1;

        // The Rates card carries its own per-card agent scope — agent is
        // not a page-level filter because most business-wide widgets
        // aren't agent-attributable facts.
        $ratesAgentId = $request->integer('agent_id') ?: null;

        $rows = $this->scopedRows($businessId, $storeIds, $since, $until)->get();
        $previousRows = $this->scopedRows(
            $businessId,
            $storeIds,
            $since->copy()->subDays($windowDays),
            $since->copy()->subDay(),
        )->get();

        $agents = User::where('business_id', $businessId)
            ->where('role', UserRole::CONFIRMATION_AGENT)
            ->get(['id', 'name', 'avatar']);

        $stores = Store::where('business_id', $businessId)->get(['id', 'name']);

        return Inertia::render('dashboard/admin', [
            'filters' => [
                'store_ids' => $storeIds !== [] ? implode(',', $storeIds) : null,
                'period' => $period !== '30d' ? $period : null,
                'agent_id' => $ratesAgentId ? (string) $ratesAgentId : null,
                // Echoed from the resolved window, not the raw params, so
                // a clamped or swapped range comes back as the dates
                // actually charted — otherwise the picker would show a
                // span the numbers below it don't cover.
                'date_from' => $period === 'custom' ? $since->toDateString() : null,
                'date_to' => $period === 'custom' ? $until->toDateString() : null,
            ],
            'stores' => $stores,
            'agents' => $agents,
            'money' => $this->moneyTiles($businessId, $rows, $previousRows),
            'ordersPerDay' => $this->ordersPerDay($rows, $since, $windowDays),
            'team' => $this->teamPerformance($businessId, $since, $until),
            'targets' => $this->rateTargets($businessId),
            'rates' => $this->rateBuckets(
                $ratesAgentId === null
                    ? $rows
                    : $this->agentRows($businessId, $ratesAgentId, $since, $until)->get(),
                $windowDays,
            ),
            'performanceTable' => $this->performanceTable($businessId, $since, $until),
            'alerts' => $this->alerts($businessId),
        ]);
    }

    /**
     * The daily rows backing the page-level widgets. No store selection
     * reads the business-wide roll-up rows; a selection reads the
     * matching store-scoped rows (summing across them per day yields the
     * multi-store totals).
     *
     * @param  list<int>  $storeIds
     * @return Builder<DailyStatsSummary>
     */
    private function scopedRows(?int $businessId, array $storeIds, Carbon $since, Carbon $until): Builder
    {
        return DailyStatsSummary::where('business_id', $businessId)
            ->when(
                $storeIds !== [],
                fn ($query) => $query->whereIn('store_id', $storeIds),
                fn ($query) => $query->whereNull('store_id'),
            )
            ->whereNull('agent_id')
            ->whereNull('product_id')
            ->whereNull('delivery_account_id')
            ->whereBetween('stat_date', [$since, $until])
            ->orderBy('stat_date');
    }

    /**
     * Agent-scoped rows for the Rates card's per-card agent filter. Agent
     * rows carry no store dimension, so an active store selection is
     * intentionally ignored while an agent is picked — the agent is the
     * narrower question.
     *
     * @return Builder<DailyStatsSummary>
     */
    private function agentRows(?int $businessId, int $agentId, Carbon $since, Carbon $until): Builder
    {
        return DailyStatsSummary::where('business_id', $businessId)
            ->where('agent_id', $agentId)
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->whereNull('delivery_account_id')
            ->whereBetween('stat_date', [$since, $until])
            ->orderBy('stat_date');
    }

    /**
     * Resolve the dashboard window from a preset name, or from an explicit
     * date_from/date_to pair when the period is 'custom'.
     *
     * A custom range that is unparseable, inverted, or missing an endpoint
     * falls back to the default preset rather than erroring: the window is
     * a view setting, and a bad link should still render a dashboard.
     * Ranges are clamped to MAX_CUSTOM_RANGE_DAYS and never reach past
     * today, since stats beyond it don't exist yet.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(string $period, Request $request): array
    {
        $today = Carbon::today();

        if ($period === 'custom') {
            $custom = $this->resolveCustomRange($request, $today);

            if ($custom !== null) {
                return $custom;
            }
        }

        return match ($period) {
            'today' => [$today->copy(), $today],
            '7d' => [$today->copy()->subDays(6), $today],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            '90d' => [$today->copy()->subDays(89), $today],
            default => [$today->copy()->subDays(self::PERIOD_DAYS - 1), $today],
        };
    }

    /**
     * The explicit date_from/date_to window, or null when the pair isn't
     * usable and the caller should fall back to a preset.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function resolveCustomRange(Request $request, Carbon $today): ?array
    {
        $from = $this->parseDate($request->string('date_from')->toString());
        $to = $this->parseDate($request->string('date_to')->toString());

        if ($from === null || $to === null) {
            return null;
        }

        // A backwards range is a mis-built link, not a request for zero
        // rows — swapping is what the user visibly meant.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($to->greaterThan($today)) {
            $to = $today->copy();
        }

        if ($from->greaterThan($to)) {
            return null;
        }

        if ($from->diffInDays($to) + 1 > self::MAX_CUSTOM_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_CUSTOM_RANGE_DAYS - 1);
        }

        return [$from, $to];
    }

    /**
     * Parse a Y-m-d query param, returning null for anything unparseable
     * so a malformed date degrades to the default window.
     */
    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  Collection<int, DailyStatsSummary>  $rows
     * @param  Collection<int, DailyStatsSummary>  $previousRows
     * @return array<string, mixed>
     */
    private function moneyTiles(?int $businessId, Collection $rows, Collection $previousRows): array
    {
        $earned = (float) $rows->sum('revenue_delivered');
        $previousEarned = (float) $previousRows->sum('revenue_delivered');

        $commissions = (float) $rows->sum('commission_total');
        $previousCommissions = (float) $previousRows->sum('commission_total');

        // Courier money is owned by courier_settlements (UC-15), not the
        // stats table: expected = what pending settlements say couriers
        // still owe; variance = how the latest closed settlement squared.
        $expected = (float) CourierSettlement::where('business_id', $businessId)
            ->where('status', 'pending')
            ->sum('expected_amount');

        $variance = CourierSettlement::where('business_id', $businessId)
            ->whereNotNull('difference_amount')
            ->latest('period_end')
            ->value('difference_amount');

        return [
            'totalEarned' => round($earned, 2),
            'totalEarnedDelta' => $this->percentDelta($earned, $previousEarned),
            'commissions' => round($commissions, 2),
            'commissionsDelta' => $this->percentDelta($commissions, $previousCommissions),
            'courierExpected' => round($expected, 2),
            'courierVariance' => $variance !== null ? (float) $variance : null,
        ];
    }

    private function percentDelta(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @param  Collection<int, DailyStatsSummary>  $rows
     * @return array<int, array{date: string, count: int}>
     */
    private function ordersPerDay(Collection $rows, Carbon $since, int $days): array
    {
        $byDate = $rows->groupBy(fn (DailyStatsSummary $row) => $row->stat_date->toDateString());

        return collect(range(0, $days - 1))->map(function (int $offset) use ($since, $byDate) {
            $date = $since->copy()->addDays($offset)->toDateString();

            return [
                'date' => $date,
                'count' => (int) ($byDate->get($date)?->sum('orders_count') ?? 0),
            ];
        })->all();
    }

    /**
     * Per-agent rates for the team radial. Agent rows carry no store
     * dimension, so this card reads business-wide agent activity
     * regardless of the store filter.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamPerformance(?int $businessId, Carbon $since, Carbon $until): array
    {
        $byAgent = DailyStatsSummary::where('business_id', $businessId)
            ->whereNotNull('agent_id')
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->whereNull('delivery_account_id')
            ->whereBetween('stat_date', [$since, $until])
            ->get()
            ->groupBy('agent_id');

        $agents = User::where('business_id', $businessId)
            ->whereIn('id', $byAgent->keys())
            ->get(['id', 'name', 'avatar'])
            ->keyBy('id');

        return $byAgent->map(function (Collection $agentRows, int $agentId) use ($agents) {
            $agent = $agents->get($agentId);

            if ($agent === null) {
                return null;
            }

            $orders = (int) $agentRows->sum('orders_count');
            $confirmed = (int) $agentRows->sum('confirmed_count');
            $submitted = (int) $agentRows->sum('submitted_to_courier_count');
            $delivered = (int) $agentRows->sum('delivered_count');

            return [
                'id' => (string) $agent->id,
                'name' => $agent->name,
                'avatar' => $agent->avatar,
                'confirmationRate' => $orders > 0 ? round(($confirmed / $orders) * 100, 1) : 0,
                'deliveryRate' => $submitted > 0 ? round(($delivered / $submitted) * 100, 1) : 0,
                'orders' => $orders,
            ];
        })->filter()->values()->all();
    }

    /**
     * Business-wide rate targets (performance_targets with user_id null),
     * falling back to sensible defaults when none are configured.
     *
     * @return array{confirmation: float, delivery: float}
     */
    private function rateTargets(?int $businessId): array
    {
        $targets = PerformanceTarget::where('business_id', $businessId)
            ->whereNull('user_id')
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (PerformanceTarget $target) => $target->metric->value);

        $confirmation = $targets->get(PerformanceMetric::CONFIRMATION_RATE->value);
        $delivery = $targets->get(PerformanceMetric::DELIVERY_SUCCESS_RATE->value);

        // Fallback covers businesses created before target seeding existed;
        // a seeded business always has the business-wide rows. Reading the
        // same config the seeder writes keeps the gauge and the agent
        // form's "Default (…)" labels from drifting apart.
        return [
            'confirmation' => $confirmation instanceof PerformanceTarget
                ? (float) $confirmation->target_percentage
                : (float) config('performance.defaults.confirmation_rate'),
            'delivery' => $delivery instanceof PerformanceTarget
                ? (float) $delivery->target_percentage
                : (float) config('performance.defaults.delivery_success_rate'),
        ];
    }

    /**
     * Time-bucketed rates for the Rates card: daily buckets for short
     * windows, weekly beyond — per-day rates on a 30/90-day COD window
     * are mostly noise.
     *
     * Also returns the window totals shown as headline numbers above the
     * lines. These are computed from the summed counts, never by
     * averaging the per-bucket rates: a mean of buckets weights a 3-order
     * day the same as a 300-order day, so it would disagree with the
     * business's real rate — and with the same figure elsewhere on the
     * page. Null when nothing happened in the window (no denominator),
     * which reads as "—" rather than a misleading 0%.
     *
     * @param  Collection<int, DailyStatsSummary>  $rows
     * @return array{buckets: array<string, array<int, array{week: string, rate: float|null, count: int, total: int}>>, totals: array{confirmation: float|null, delivery: float|null, return: float|null}, counts: array<string, array{count: int, total: int}>}
     */
    private function rateBuckets(Collection $rows, int $windowDays): array
    {
        $grouped = $rows->groupBy(fn (DailyStatsSummary $row) => $windowDays <= 14
            ? $row->stat_date->toDateString()
            : $row->stat_date->copy()->startOfWeek()->toDateString());

        $confirmation = [];
        $delivery = [];
        $return = [];

        foreach ($grouped->sortKeys() as $bucketStart => $bucketRows) {
            $label = Carbon::parse($bucketStart)->format('M j');

            $orders = (int) $bucketRows->sum('orders_count');
            $confirmed = (int) $bucketRows->sum('confirmed_count');
            $submitted = (int) $bucketRows->sum('submitted_to_courier_count');
            $delivered = (int) $bucketRows->sum('delivered_count');
            $returned = (int) $bucketRows->sum('returned_count');

            // count / total ride along with each rate so the chart can
            // show the real numbers, not just a percentage.
            $confirmation[] = ['week' => $label, 'rate' => $orders > 0 ? round(($confirmed / $orders) * 100, 1) : null, 'count' => $confirmed, 'total' => $orders];
            $delivery[] = ['week' => $label, 'rate' => $submitted > 0 ? round(($delivered / $submitted) * 100, 1) : null, 'count' => $delivered, 'total' => $submitted];
            $return[] = ['week' => $label, 'rate' => $delivered > 0 ? round(($returned / $delivered) * 100, 1) : null, 'count' => $returned, 'total' => $delivered];
        }

        $totalOrders = (int) $rows->sum('orders_count');
        $totalConfirmed = (int) $rows->sum('confirmed_count');
        $totalSubmitted = (int) $rows->sum('submitted_to_courier_count');
        $totalDelivered = (int) $rows->sum('delivered_count');
        $totalReturned = (int) $rows->sum('returned_count');

        return [
            'buckets' => [
                'confirmation' => $confirmation,
                'delivery' => $delivery,
                'return' => $return,
            ],
            'totals' => [
                'confirmation' => $totalOrders > 0 ? round(($totalConfirmed / $totalOrders) * 100, 1) : null,
                'delivery' => $totalSubmitted > 0 ? round(($totalDelivered / $totalSubmitted) * 100, 1) : null,
                'return' => $totalDelivered > 0 ? round(($totalReturned / $totalDelivered) * 100, 1) : null,
            ],
            'counts' => [
                'confirmation' => ['count' => $totalConfirmed, 'total' => $totalOrders],
                'delivery' => ['count' => $totalDelivered, 'total' => $totalSubmitted],
                'return' => ['count' => $totalReturned, 'total' => $totalDelivered],
            ],
        ];
    }

    /**
     * Ranked store / product / courier rows. These read their own
     * dimension rows (store-, product-, courier-scoped), which carry no
     * second store dimension — so the page's store filter doesn't apply
     * here; the tabs themselves are the breakdown.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function performanceTable(?int $businessId, Carbon $since, Carbon $until): array
    {
        $dimensionRows = fn (string $column) => DailyStatsSummary::where('business_id', $businessId)
            ->whereNotNull($column)
            ->whereBetween('stat_date', [$since, $until])
            ->get()
            ->groupBy($column);

        $mapCommon = function (Collection $rows): array {
            $orders = (int) $rows->sum('orders_count');
            $confirmed = (int) $rows->sum('confirmed_count');
            $submitted = (int) $rows->sum('submitted_to_courier_count');
            $delivered = (int) $rows->sum('delivered_count');

            return [
                'orders' => $orders,
                'confirmationRate' => $orders > 0 ? round(($confirmed / $orders) * 100, 1) : 0,
                'deliveryRate' => $submitted > 0 ? round(($delivered / $submitted) * 100, 1) : 0,
                'earned' => round((float) $rows->sum('revenue_delivered'), 2),
                // The counts behind each rate, so the table can show
                // "64% (32 / 50)" rather than a bare percentage.
                'confirmed' => $confirmed,
                'submitted' => $submitted,
                'delivered' => $delivered,
            ];
        };

        $storeRows = $dimensionRows('store_id');
        $storeNames = Store::where('business_id', $businessId)
            ->whereIn('id', $storeRows->keys())
            ->get(['id', 'name', 'logo_url'])
            ->keyBy('id');

        $stores = $storeRows->map(function (Collection $rows, int $storeId) use ($mapCommon, $storeNames) {
            $store = $storeNames->get($storeId);

            // A deleted store keeps its history: stats rows have no foreign
            // key to `stores`, so they outlive it. Dropping them here would
            // hide real orders from the breakdown while the totals above
            // still counted them, leaving the two disagreeing.
            if ($store === null) {
                return [
                    // first() is safe: a group only exists because it has rows.
                    'name' => $rows->first()->store_name ?? __('Deleted store'),
                    'image' => null,
                    ...$mapCommon($rows),
                ];
            }

            return [
                'name' => $store->name,
                'image' => $store->logo_url,
                ...$mapCommon($rows),
            ];
        })->values();

        $productRows = $dimensionRows('product_id');
        $productNames = Product::where('business_id', $businessId)
            ->whereIn('id', $productRows->keys())
            ->get(['id', 'name', 'thumbnail'])
            ->keyBy('id');

        $products = $productRows->map(function (Collection $rows, int $productId) use ($mapCommon, $productNames) {
            $product = $productNames->get($productId);

            return $product === null ? null : [
                'name' => $product->name,
                'image' => $product->thumbnail,
                ...$mapCommon($rows),
            ];
        })->filter()->sortByDesc('orders')->take(10)->values();

        $courierRows = $dimensionRows('delivery_account_id');
        $accounts = DeliveryAccount::where('business_id', $businessId)
            ->whereIn('id', $courierRows->keys())
            ->with('courier:id,logo')
            ->get()
            ->keyBy('id');

        $couriers = $courierRows->map(function (Collection $rows, int $accountId) use ($mapCommon, $accounts) {
            $account = $accounts->get($accountId);

            if ($account === null) {
                return null;
            }

            $common = $mapCommon($rows);
            $deliverySeconds = (int) $rows->sum('delivery_seconds_total');

            return [
                'name' => $account->label,
                'image' => $account->courier?->logo,
                ...$common,
                // A courier's volume is shipments, not order creations —
                // orders_count never lands on courier rows because no
                // courier is attached yet when an order is created.
                'orders' => (int) $rows->sum('submitted_to_courier_count'),
                // Couriers only ever see confirmed orders.
                'confirmationRate' => null,
                'avgDeliveryDays' => $common['delivered'] > 0
                    ? round($deliverySeconds / $common['delivered'] / 86400, 1)
                    : null,
            ];
        })->filter()->values();

        return [
            'stores' => $stores->all(),
            'products' => $products->all(),
            'couriers' => $couriers->all(),
        ];
    }

    /**
     * Operational alerts — current-state counts, not stats. Each carries
     * enough for the page to render an act-now button.
     *
     * @return array<int, array<string, int|string>>
     */
    private function alerts(?int $businessId): array
    {
        $alerts = [];

        $unassigned = Order::where('business_id', $businessId)
            ->where('is_test', false)
            ->where('confirmation_status', OrderConfirmationStatus::NEW)
            ->whereNull('assigned_agent_id')
            ->count();

        if ($unassigned > 0) {
            $alerts[] = ['id' => 'unassigned', 'count' => $unassigned];
        }

        $staleTransit = Order::where('business_id', $businessId)
            ->where('is_delivery_active', true)
            ->where('updated_at', '<', Carbon::now()->subDays(self::STALE_TRANSIT_DAYS))
            ->count();

        if ($staleTransit > 0) {
            $alerts[] = ['id' => 'stale-transit', 'count' => $staleTransit];
        }

        return $alerts;
    }

    private function agentDashboard(User $user): Response
    {
        $businessId = $user->business_id;
        $since = Carbon::today()->subDays(self::PERIOD_DAYS - 1);

        $rows = DailyStatsSummary::where('business_id', $businessId)
            ->where('agent_id', $user->id)
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->whereNull('delivery_account_id')
            ->where('stat_date', '>=', $since)
            ->orderBy('stat_date')
            ->get();

        $series = $this->zeroFilledSeries($rows, $since, self::PERIOD_DAYS);

        $totals = [
            'assigned' => (int) $rows->sum('orders_count'),
            'confirmed' => (int) $rows->sum('confirmed_count'),
            'delivered' => (int) $rows->sum('delivered_count'),
            'cancelled' => (int) $rows->sum('cancelled_count'),
        ];

        // Window totals from summed counts, never an average of the stored
        // per-day rates: a day with 2 parcels would otherwise weigh the
        // same as a day with 200, so the headline would disagree with the
        // agent's real rate. Null when nothing shipped, which reads as
        // "--" rather than a demoralising 0%.
        $submitted = (int) $rows->sum('submitted_to_courier_count');
        $confirmationTotal = $totals['assigned'] > 0
            ? round(($totals['confirmed'] / $totals['assigned']) * 100, 1)
            : null;
        $deliveryTotal = $submitted > 0
            ? round(($totals['delivered'] / $submitted) * 100, 1)
            : null;

        // Per-tile shares, each against the denominator that makes the
        // number mean something. Delivered divides by what actually
        // reached a courier, not by everything assigned: an agent is not
        // accountable for parcels that were never shipped, and dividing
        // by assigned would quietly understate every agent. Cancelled and
        // confirmed both divide by assigned, since together with the
        // still-pending remainder that is what an agent was handed.
        $tileRates = [
            'confirmed' => $totals['assigned'] > 0
                ? (int) round(($totals['confirmed'] / $totals['assigned']) * 100)
                : null,
            'delivered' => $submitted > 0
                ? (int) round(($totals['delivered'] / $submitted) * 100)
                : null,
            'cancelled' => $totals['assigned'] > 0
                ? (int) round(($totals['cancelled'] / $totals['assigned']) * 100)
                : null,
        ];

        return Inertia::render('dashboard/agent', [
            'periodDays' => self::PERIOD_DAYS,
            'totals' => $totals,
            'tileRates' => $tileRates,
            'confirmationRateTrend' => $series->map(fn ($row) => [
                'date' => $row['stat_date'],
                'rate' => $row['confirmation_rate'],
            ]),
            'confirmationRateTarget' => $this->agentTarget($businessId, $user, PerformanceMetric::CONFIRMATION_RATE)?->target_percentage,
            'deliveryRateTrend' => $series->map(fn ($row) => [
                'date' => $row['stat_date'],
                'rate' => $row['delivery_success_rate'],
            ]),
            'deliveryRateTarget' => $this->agentTarget($businessId, $user, PerformanceMetric::DELIVERY_SUCCESS_RATE)?->target_percentage,
            'rateTotals' => [
                'confirmation' => $confirmationTotal,
                'delivery' => $deliveryTotal,
            ],
            'commissionEarned' => round((float) $rows->sum('commission_total'), 2),
        ]);
    }

    /**
     * The active target for one metric as it applies to this agent.
     *
     * An agent-specific row (user_id set) takes precedence over the
     * business-wide default (user_id null), matching PerformanceTarget's
     * own doc and the precedence AgentPerformanceEvaluator applies. The
     * orderByRaw puts non-null user_id first, so first() is the agent's
     * own row whenever one exists.
     */
    private function agentTarget(?int $businessId, User $user, PerformanceMetric $metric): ?PerformanceTarget
    {
        return PerformanceTarget::where('business_id', $businessId)
            ->where('metric', $metric)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhereNull('user_id'))
            ->orderByRaw('user_id is null')
            ->first();
    }

    /**
     * Fill any date in the period missing a summary row with zeros, so
     * charts always render a full, continuous range instead of gaps.
     *
     * @param  Collection<int, DailyStatsSummary>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function zeroFilledSeries(Collection $rows, Carbon $since, int $days): Collection
    {
        $byDate = $rows->keyBy(fn ($row) => $row->stat_date->toDateString());

        return collect(range(0, $days - 1))->map(function (int $offset) use ($since, $byDate): array {
            $date = $since->copy()->addDays($offset)->toDateString();
            $row = $byDate->get($date);

            /** @var array<string, mixed> $result */
            $result = [
                'stat_date' => $date,
                'orders_count' => $row ? (int) $row->orders_count : 0,
                'confirmation_rate' => $row?->confirmation_rate,
                'delivery_success_rate' => $row?->delivery_success_rate,
            ];

            return $result;
        });
    }
}
