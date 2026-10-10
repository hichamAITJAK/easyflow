<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\PerformanceMetric;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CourierSettlement;
use App\Models\DailyStatsSummary;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Models\OrderStatusEvent;
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

    /** How many equal spans the KPI sparklines split the window into. */
    private const KPI_SLICES = 8;

    /** A product delivering under this share of its confirmed orders is flagged… */
    private const LOW_DELIVERY_PCT = 50;

    /** …once it has at least this many confirmed orders in the period. */
    private const LOW_DELIVERY_MIN_CONFIRMED = 20;

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

        return $this->agentDashboard($user, $request);
    }

    /**
     * The admin dashboard: one props payload built from the stats tables
     * (never raw orders, except the few current-state snapshots noted
     * on each method) for the selected stores / period / agent.
     */
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

        // The agent filter only narrows the Performance track; the rest
        // of the page keeps describing the whole business.
        $agentId = $request->integer('agent_id') ?: null;
        $agent = $agentId === null
            ? null
            : User::where('business_id', $businessId)
                ->where('role', UserRole::CONFIRMATION_AGENT)
                ->find($agentId, ['id', 'name']);

        $rows = $this->scopedRows($businessId, $storeIds, $since, $until)->get();
        $previousRows = $this->scopedRows(
            $businessId,
            $storeIds,
            $since->copy()->subDays($windowDays),
            $since->copy()->subDay(),
        )->get();

        $agents = User::where('business_id', $businessId)
            ->where('role', UserRole::CONFIRMATION_AGENT)
            ->orderBy('name')
            ->get(['id', 'name']);

        $stores = Store::where('business_id', $businessId)->orderBy('name')->get(['id', 'name']);

        $breakdown = $this->breakdown($businessId, $since, $until);
        $goals = $this->goals($businessId);

        return Inertia::render('dashboard/admin', [
            'filters' => [
                'store_ids' => $storeIds !== [] ? implode(',', $storeIds) : null,
                'period' => $period !== '30d' ? $period : null,
                'agent_id' => $agent?->id !== null ? (string) $agent->id : null,
                // Echoed from the resolved window, not the raw params, so
                // a clamped or swapped range comes back as the dates
                // actually charted.
                'date_from' => $period === 'custom' ? $since->toDateString() : null,
                'date_to' => $period === 'custom' ? $until->toDateString() : null,
            ],
            'stores' => $stores,
            'agents' => $agents,
            'alerts' => $this->attentionAlerts($businessId, $breakdown['products']),
            'kpis' => $this->kpis($businessId, $storeIds, $rows, $previousRows, $since, $until),
            'income' => $this->income($businessId, $storeIds, $since, $until),
            'expected' => $this->expected($businessId),
            'parcels' => $this->parcels($businessId, $storeIds, $since, $until),
            'performance' => $this->performance($businessId, $agent, $rows, $since, $until, $windowDays, $goals),
            'breakdown' => $breakdown,
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
     * Agent-scoped rows. Agent rows carry no store dimension, so an
     * active store selection is intentionally ignored while an agent is
     * picked — the agent is the narrower question.
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
     * Needs-attention strip. Three rules, each a current-state check:
     * orders sitting with an agent for over a day, a settlement that
     * did not match, and products delivering under half of what they
     * confirmed. Empty when nothing needs attention.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function attentionAlerts(?int $businessId, array $products): array
    {
        $alerts = [];

        $stale = $this->inProgressQuery($businessId)
            ->where('created_at', '<', Carbon::now()->subDay())
            ->count();

        if ($stale > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'kind' => 'stale_in_progress',
                'strong' => trans_choice(':count order|:count orders', $stale, ['count' => $stale]),
                'text' => __('in progress for more than 24h'),
                'action' => [
                    'label' => __('Review'),
                    'href' => route('orders.index', ['confirmation_status' => OrderConfirmationStatus::ASSIGNED->value]),
                ],
            ];
        }

        $settlement = CourierSettlement::where('business_id', $businessId)
            ->where('status', '!=', 'pending')
            ->with('deliveryAccount.courier:id,name')
            ->latest('period_end')
            ->first();

        if ($settlement !== null && (float) $settlement->difference_amount != 0.0) {
            $difference = (float) $settlement->difference_amount;

            $alerts[] = [
                'severity' => 'critical',
                'kind' => 'settlement_difference',
                'strong' => ($difference < 0 ? '−' : '+').number_format(abs($difference), 0, '.', ',').' MAD',
                'text' => __('settlement difference with :courier', [
                    'courier' => $settlement->deliveryAccount?->courier?->name ?? $settlement->deliveryAccount?->label ?? __('courier'),
                ]),
                'action' => ['label' => __('Settle'), 'href' => route('settlements.index')],
            ];
        }

        $low = collect($products)->filter(fn (array $row) => $row['conf'] !== null
            && $row['conf'][0] >= self::LOW_DELIVERY_MIN_CONFIRMED
            && $row['deliv'][1] < self::LOW_DELIVERY_PCT);

        if ($low->isNotEmpty()) {
            $alerts[] = [
                'severity' => 'critical',
                'kind' => 'low_delivery_product',
                'strong' => $low->count() === 1
                    ? $low->first()['name']
                    : trans_choice(':count product|:count products', $low->count(), ['count' => $low->count()]),
                'text' => $low->count() === 1
                    ? __('delivering at :rate% — below :threshold%', ['rate' => $low->first()['deliv'][1], 'threshold' => self::LOW_DELIVERY_PCT])
                    : __('delivering below :threshold%', ['threshold' => self::LOW_DELIVERY_PCT]),
                // The page handles this one: it jumps to the Products tab.
                'action' => ['label' => __('Inspect'), 'href' => '#breakdown'],
            ];
        }

        return $alerts;
    }

    /**
     * Orders currently with an agent and without an outcome yet.
     *
     * @return Builder<Order>
     */
    private function inProgressQuery(?int $businessId): Builder
    {
        return Order::where('business_id', $businessId)
            ->where('is_test', false)
            ->whereIn('confirmation_status', [
                OrderConfirmationStatus::NEW,
                OrderConfirmationStatus::ASSIGNED,
                OrderConfirmationStatus::CALLBACK,
                OrderConfirmationStatus::VOICEMAIL,
                OrderConfirmationStatus::NO_ANSWER,
                OrderConfirmationStatus::BUSY,
                OrderConfirmationStatus::WHATSAPP_SENT,
            ]);
    }

    /**
     * The funnel tiles. Received, confirmed, delivered and returned come
     * from the period rows (with the previous window of the same length
     * for the deltas); in-progress is a now snapshot of the orders table.
     *
     * @param  list<int>  $storeIds
     * @param  Collection<int, DailyStatsSummary>  $rows
     * @param  Collection<int, DailyStatsSummary>  $previousRows
     * @return array<string, mixed>
     */
    private function kpis(?int $businessId, array $storeIds, Collection $rows, Collection $previousRows, Carbon $since, Carbon $until): array
    {
        $sum = fn (Collection $set, string $column): int => (int) $set->sum($column);

        $received = $sum($rows, 'orders_count');
        $confirmed = $sum($rows, 'confirmed_count');
        $delivered = $sum($rows, 'delivered_count');
        $returned = $sum($rows, 'returned_count');

        $inProgress = $this->inProgressQuery($businessId)
            ->when($storeIds !== [], fn ($query) => $query->whereIn('store_id', $storeIds));

        $agentIds = (clone $inProgress)->whereNotNull('assigned_agent_id')->distinct()->pluck('assigned_agent_id');
        $agentNames = User::whereIn('id', $agentIds)->orderBy('name')->pluck('name');

        $inProgressCount = $inProgress->count();

        return [
            'received' => [
                'value' => $received,
                'deltaPct' => $this->percentDelta($received, $sum($previousRows, 'orders_count')),
                'buckets' => $this->slices($rows, 'orders_count', $since, $until),
            ],
            'inProgress' => [
                'value' => $inProgressCount,
                'sharePct' => $received > 0 ? round($inProgressCount / $received * 100, 1) : 0,
                // No yesterday snapshot is stored, so no delta is claimed.
                'deltaPct' => null,
                'agentInitials' => $agentNames->map(fn (string $name) => $this->initials($name))->values()->all(),
                'agentCount' => $agentNames->count(),
            ],
            'confirmed' => [
                'value' => $confirmed,
                'ratePct' => $this->pct($confirmed, $received),
                'deltaPct' => $this->percentDelta($confirmed, $sum($previousRows, 'confirmed_count')),
                'of' => $received,
            ],
            'delivered' => [
                'value' => $delivered,
                'ratePct' => $this->pct($delivered, $confirmed),
                'deltaPct' => $this->percentDelta($delivered, $sum($previousRows, 'delivered_count')),
                'trend' => $this->slices($rows, 'delivered_count', $since, $until),
            ],
            'returned' => [
                'value' => $returned,
                'ratePct' => $this->pct($returned, $confirmed),
                'deltaPct' => $this->percentDelta($returned, $sum($previousRows, 'returned_count')),
                'buckets' => $this->slices($rows, 'returned_count', $since, $until),
            ],
        ];
    }

    /**
     * The window split into KPI_SLICES equal spans, each summing one
     * column; a one-day window yields one slice.
     *
     * @param  Collection<int, DailyStatsSummary>  $rows
     * @return array<int, array{label: string, value: int}>
     */
    private function slices(Collection $rows, string $column, Carbon $since, Carbon $until): array
    {
        $days = (int) $since->diffInDays($until) + 1;
        $count = min(self::KPI_SLICES, $days);
        $byDate = $rows->groupBy(fn (DailyStatsSummary $row) => $row->stat_date->toDateString());

        return collect(range(0, $count - 1))->map(function (int $index) use ($since, $days, $count, $byDate, $column) {
            $start = $since->copy()->addDays((int) floor($index * $days / $count));
            $end = $since->copy()->addDays((int) floor(($index + 1) * $days / $count) - 1);

            $value = 0;
            for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
                $value += (int) ($byDate->get($day->toDateString())?->sum($column) ?? 0);
            }

            return [
                'label' => $start->equalTo($end)
                    ? $start->format('M j')
                    : $start->format('M j').' – '.$end->format('M j'),
                'value' => $value,
            ];
        })->all();
    }

    /**
     * Money brought in by delivered orders over the selected period, in
     * buckets sized to the window: one per day up to a month, one per
     * week up to four months, one per month beyond. Follows the store
     * and period filters like the KPI tiles above it.
     *
     * @param  list<int>  $storeIds
     * @return array{grain: string, totalMad: float, buckets: array<int, array{label: string, amountMad: float, ordersSettled: int}>}
     */
    private function income(?int $businessId, array $storeIds, Carbon $since, Carbon $until): array
    {
        $rows = $this->scopedRows($businessId, $storeIds, $since, $until)->get();
        $byDate = $rows->groupBy(fn (DailyStatsSummary $row) => $row->stat_date->toDateString());
        $days = (int) $since->diffInDays($until) + 1;

        $grain = match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };

        $buckets = [];
        $cursor = $since->copy()->startOfDay();
        $last = $until->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $end = match ($grain) {
                'day' => $cursor->copy(),
                'week' => $cursor->copy()->addDays(6),
                default => $cursor->copy()->endOfMonth()->startOfDay(),
            };

            if ($end->greaterThan($last)) {
                $end = $last->copy();
            }

            $amount = 0.0;
            $settled = 0;

            for ($day = $cursor->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
                $dayRows = $byDate->get($day->toDateString());
                $amount += (float) ($dayRows?->sum('revenue_delivered') ?? 0);
                $settled += (int) ($dayRows?->sum('delivered_count') ?? 0);
            }

            $buckets[] = [
                'label' => match ($grain) {
                    'day' => $cursor->format('M j'),
                    'week' => $cursor->format('M j'),
                    default => $cursor->format('M'),
                },
                'amountMad' => round($amount, 2),
                'ordersSettled' => $settled,
            ];

            $cursor = $end->copy()->addDay();
        }

        return [
            'grain' => $grain,
            'totalMad' => round((float) $rows->sum('revenue_delivered'), 2),
            'buckets' => $buckets,
        ];
    }

    /**
     * Courier cash not yet in hand, owned by courier_settlements (UC-15):
     * what pending settlements say couriers still owe, the delivered
     * parcels no closed settlement covers, and how the last closed one
     * squared.
     *
     * @return array<string, mixed>
     */
    private function expected(?int $businessId): array
    {
        $toReceive = (float) CourierSettlement::where('business_id', $businessId)
            ->where('status', 'pending')
            ->sum('expected_amount');

        $deliveredUnpaid = Order::where('business_id', $businessId)
            ->where('is_test', false)
            ->where('delivery_status', OrderDeliveryStatus::DELIVERED)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('courier_settlements')
                ->whereColumn('courier_settlements.delivery_account_id', 'orders.delivery_account_id')
                ->where('courier_settlements.status', '!=', 'pending')
                ->whereNull('courier_settlements.deleted_at')
                ->whereColumn('courier_settlements.period_start', '<=', 'orders.shipped_at')
                ->whereColumn('courier_settlements.period_end', '>=', 'orders.shipped_at'))
            ->count();

        $last = CourierSettlement::where('business_id', $businessId)
            ->where('status', '!=', 'pending')
            ->latest('period_end')
            ->first();

        $difference = $last === null ? 0.0 : (float) $last->difference_amount;

        return [
            'toReceiveMad' => round($toReceive, 2),
            'deliveredUnpaid' => $deliveredUnpaid,
            'lastSettlement' => $last === null
                ? null
                : [
                    'status' => $difference == 0.0 ? 'matched' : 'difference',
                    'differenceMad' => round($difference, 2),
                ],
        ];
    }

    /**
     * The shipment pipeline: the period's parcels by current stage, each
     * with its share of the total.
     *
     * @param  list<int>  $storeIds
     * @return array{total: int, stages: array<int, array{key: string, label: string, count: int, ratePct: float}>}
     */
    private function parcels(?int $businessId, array $storeIds, Carbon $since, Carbon $until): array
    {
        $stages = $this->parcelStages($businessId, $storeIds, $since, $until);
        $total = array_sum($stages);

        $labels = [
            'ready' => ['ready_to_ship', __('Ready to ship')],
            'shipped' => ['shipped', __('Shipped')],
            'delivered' => ['delivered', __('Delivered')],
            'returned' => ['returned', __('Returned')],
        ];

        return [
            'total' => $total,
            'stages' => collect($stages)->map(fn (int $count, string $stage) => [
                'key' => $labels[$stage][0],
                'label' => $labels[$stage][1],
                'count' => $count,
                'ratePct' => $this->pct($count, $total),
            ])->values()->all(),
        ];
    }

    /**
     * The Performance track: a per-day series, the outcome figures and
     * the goal panel, for one agent or the whole team.
     *
     * @param  Collection<int, DailyStatsSummary>  $rows
     * @param  array{conf: float, deliv: float}  $goals
     * @return array<string, mixed>
     */
    private function performance(?int $businessId, ?User $agent, Collection $rows, Carbon $since, Carbon $until, int $windowDays, array $goals): array
    {
        $teamRows = DailyStatsSummary::where('business_id', $businessId)
            ->whereNotNull('agent_id')
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->whereNull('delivery_account_id')
            ->whereBetween('stat_date', [$since, $until])
            ->get();

        $scoped = $agent === null ? $rows : $teamRows->where('agent_id', $agent->id);

        $orders = (int) $scoped->sum('orders_count');
        $confirmed = (int) $scoped->sum('confirmed_count');
        $delivered = (int) $scoped->sum('delivered_count');
        $returned = (int) $scoped->sum('returned_count');
        $commissions = (float) $scoped->sum('commission_total');

        $agentCount = $teamRows->pluck('agent_id')->unique()->count();

        $confPct = $this->pct($confirmed, $orders);
        $delivPct = $this->pct($delivered, $confirmed);

        $agentNames = User::where('business_id', $businessId)
            ->whereIn('id', $teamRows->pluck('agent_id')->unique())
            ->pluck('name', 'id');

        $agentViews = $teamRows->groupBy('agent_id')
            ->map(function (Collection $agentRows, int $agentId) use ($agentNames, $goals) {
                $name = $agentNames->get($agentId);

                if ($name === null) {
                    return null;
                }

                $conf = $this->pct((int) $agentRows->sum('confirmed_count'), (int) $agentRows->sum('orders_count'));
                $deliv = $this->pct((int) $agentRows->sum('delivered_count'), (int) $agentRows->sum('confirmed_count'));
                $attainment = $this->attainment($conf, $deliv, $goals);

                return [
                    'id' => $agentId,
                    'name' => $name,
                    'attainmentPct' => $attainment,
                    'status' => $this->attainmentStatus($attainment),
                    'confPct' => $conf,
                    'delivPct' => $deliv,
                ];
            })
            ->filter()
            ->sortByDesc('attainmentPct')
            ->values();

        $attainment = $this->attainment($confPct, $delivPct, $goals);

        $view = $agent === null
            ? [
                'type' => 'team',
                'attainmentPct' => $attainment,
                'status' => $this->attainmentStatus($attainment),
                'confPct' => $confPct,
                'delivPct' => $delivPct,
                'agents' => $agentViews->map(fn (array $row) => collect($row)->except('id')->all())->all(),
            ]
            : [
                'type' => 'agent',
                'name' => $agent->name,
                'ordersHandled' => $orders,
                'attainmentPct' => $attainment,
                'status' => $this->attainmentStatus($attainment),
                'confPct' => $confPct,
                'delivPct' => $delivPct,
                'commissionsMad' => round($commissions, 2),
            ];

        return [
            'rangeLabel' => $since->format('M j').' – '.$until->format('M j'),
            'daily' => $this->ordersPerDay($scoped, $since, $windowDays),
            'ordersLabel' => $agent === null
                ? __('orders received')
                : __('orders handled by :name', ['name' => $agent->name]),
            'outcomes' => [
                'conf' => [$confirmed, $confPct],
                'deliv' => [$delivered, $delivPct],
                'ret' => [$returned, $this->pct($returned, $confirmed)],
                'commissionsMad' => round($commissions, 2),
                'commissionsSub' => $agent === null
                    ? trans_choice('MAD · :count agent|MAD · :count agents', $agentCount, ['count' => $agentCount])
                    : 'MAD',
            ],
            'goals' => $goals,
            'view' => $view,
        ];
    }

    /**
     * One point per day of the window.
     *
     * @param  Collection<int, DailyStatsSummary>  $rows
     * @return array<int, array{label: string, value: int}>
     */
    private function ordersPerDay(Collection $rows, Carbon $since, int $days): array
    {
        $byDate = $rows->groupBy(fn (DailyStatsSummary $row) => $row->stat_date->toDateString());

        return collect(range(0, $days - 1))->map(function (int $offset) use ($since, $byDate) {
            $date = $since->copy()->addDays($offset);

            return [
                'label' => $date->format('M j'),
                'value' => (int) ($byDate->get($date->toDateString())?->sum('orders_count') ?? 0),
            ];
        })->all();
    }

    /**
     * Business-wide rate goals: the configured defaults, which are what
     * the agent form labels "Default (…)".
     *
     * @return array{conf: float, deliv: float}
     */
    private function goals(?int $businessId): array
    {
        $targets = PerformanceTarget::where('business_id', $businessId)
            ->whereNull('user_id')
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (PerformanceTarget $target) => $target->metric->value);

        return [
            'conf' => (float) ($targets->get(PerformanceMetric::CONFIRMATION_RATE->value)?->target_percentage
                ?? config('performance.defaults.confirmation_rate')),
            'deliv' => (float) ($targets->get(PerformanceMetric::DELIVERY_SUCCESS_RATE->value)?->target_percentage
                ?? config('performance.defaults.delivery_success_rate')),
        ];
    }

    /**
     * Goal attainment: the mean of each rate over its goal, as a whole
     * percentage (can exceed 100).
     *
     * @param  array{conf: float, deliv: float}  $goals
     */
    private function attainment(float $confPct, float $delivPct, array $goals): int
    {
        $parts = [];

        if ($goals['conf'] > 0) {
            $parts[] = $confPct / $goals['conf'];
        }

        if ($goals['deliv'] > 0) {
            $parts[] = $delivPct / $goals['deliv'];
        }

        return $parts === [] ? 0 : (int) round(array_sum($parts) / count($parts) * 100);
    }

    private function attainmentStatus(int $attainment): string
    {
        return match (true) {
            $attainment >= 105 => 'Excellent',
            $attainment >= 90 => 'Good',
            $attainment >= 75 => 'Average',
            default => 'Low',
        };
    }

    /**
     * Breakdown tabs: each store / product / courier as
     * {name, img, orders, conf, deliv, ret} with [count, ratePct] pairs.
     * conf is null for couriers, who only ever see confirmed orders.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function breakdown(?int $businessId, Carbon $since, Carbon $until): array
    {
        $table = $this->performanceTable($businessId, $since, $until);

        $map = fn (array $row): array => [
            'name' => $row['name'],
            'img' => $row['image'],
            'orders' => $row['orders'],
            'conf' => $row['confirmationRate'] === null
                ? null
                : [$row['confirmed'], $this->pct($row['confirmed'], $row['orders'])],
            'deliv' => [$row['delivered'], $this->pct($row['delivered'], $row['confirmed'])],
            'ret' => [$row['returned'], $this->pct($row['returned'], $row['confirmed'])],
        ];

        return [
            'stores' => array_map($map, $table['stores']),
            'products' => array_map($map, $table['products']),
            'couriers' => array_map(fn (array $row) => [
                ...$map($row),
                // Couriers: every parcel they carried counts as confirmed.
                'deliv' => [$row['delivered'], $this->pct($row['delivered'], $row['orders'])],
                'ret' => [$row['returned'], $this->pct($row['returned'], $row['orders'])],
            ], $table['couriers']),
        ];
    }

    /** Whole-ish percentage with one decimal; 0 when the base is empty. */
    private function pct(int $count, int $base): float
    {
        return $base > 0 ? round($count / $base * 100, 1) : 0.0;
    }

    private function percentDelta(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = implode('', array_map(fn (string $part) => mb_substr($part, 0, 1), array_slice($parts, 0, 2)));

        return mb_strtoupper($initials);
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
     * Where the period's parcels are right now, folded into the four
     * stages an owner thinks in. Counts orders placed inside the window
     * by their current delivery status, so the four numbers describe the
     * same set of orders as the rest of the page.
     *
     * A live grouped count rather than the stats table on purpose: the
     * daily columns record that a parcel was ever shipped or delivered,
     * not where it sits today, so "ready" and "still with the courier"
     * cannot be derived from them.
     *
     * Parcels cancelled at the courier belong to no stage and are left
     * out.
     *
     * @param  list<int>  $storeIds
     * @return array{ready: int, shipped: int, delivered: int, returned: int}
     */
    private function parcelStages(?int $businessId, array $storeIds, Carbon $since, Carbon $until): array
    {
        $byStatus = Order::where('business_id', $businessId)
            ->where('is_test', false)
            ->whereNotNull('delivery_status')
            ->whereBetween('created_at', [$since->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->when($storeIds !== [], fn ($query) => $query->whereIn('store_id', $storeIds))
            ->toBase()
            ->selectRaw('delivery_status, count(*) as aggregate')
            ->groupBy('delivery_status')
            ->pluck('aggregate', 'delivery_status');

        $sum = fn (array $statuses): int => (int) collect($statuses)
            ->sum(fn (OrderDeliveryStatus $status) => (int) ($byStatus[$status->value] ?? 0));

        return [
            // Registered or staged, not yet collected by the courier.
            'ready' => $sum([
                OrderDeliveryStatus::AWAITING_PICKUP,
                OrderDeliveryStatus::READY_FOR_PICKUP,
            ]),
            // With the courier and still on its way to the customer.
            'shipped' => $sum([
                OrderDeliveryStatus::IN_TRANSIT,
                OrderDeliveryStatus::OUT_FOR_DELIVERY,
                OrderDeliveryStatus::POSTPONED,
                OrderDeliveryStatus::CHANGED,
                OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED,
            ]),
            'delivered' => $sum([OrderDeliveryStatus::DELIVERED]),
            // Refused at the door, on the way back, or back in the warehouse.
            'returned' => $sum([
                OrderDeliveryStatus::REFUSED,
                OrderDeliveryStatus::RETURNED_IN_TRANSIT,
                OrderDeliveryStatus::RETURN_RECEIVED,
            ]),
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
            $returned = (int) $rows->sum('returned_count');

            return [
                'orders' => $orders,
                'confirmationRate' => $orders > 0 ? round(($confirmed / $orders) * 100, 1) : 0,
                'deliveryRate' => $submitted > 0 ? round(($delivered / $submitted) * 100, 1) : 0,
                // The counts behind each rate, so the table can show
                // "64% (32 / 50)" rather than a bare percentage.
                'confirmed' => $confirmed,
                'submitted' => $submitted,
                'delivered' => $delivered,
                'returned' => $returned,
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

    private function agentDashboard(User $user, Request $request): Response
    {
        $businessId = $user->business_id;

        // Same window rules as the admin view (presets + custom range),
        // so a link copied between the two dashboards means the same days.
        $period = $request->string('period')->toString() ?: '30d';
        [$since, $until] = $this->resolvePeriod($period, $request);
        $windowDays = (int) $since->diffInDays($until) + 1;

        $rows = DailyStatsSummary::where('business_id', $businessId)
            ->where('agent_id', $user->id)
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->whereNull('delivery_account_id')
            ->whereBetween('stat_date', [$since, $until])
            ->orderBy('stat_date')
            ->get();

        $series = $this->zeroFilledSeries($rows, $since, $windowDays);

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

        // Upsells and the best confirm hour come straight from orders and
        // their status events: neither is rolled into the daily stats.
        $upsells = Order::where('assigned_agent_id', $user->id)
            ->where('upsell_amount', '>', 0)
            ->whereBetween('created_at', [$since->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->count();

        return Inertia::render('dashboard/agent', [
            // The same Performance track the admin sees, pinned to this
            // agent: their own rows, no selector.
            'performance' => $this->performance($businessId, $user, $rows, $since, $until, $windowDays, $this->goals($businessId)),
            'filters' => [
                'period' => $period !== '30d' ? $period : null,
                // Echoed from the resolved window (see adminDashboard).
                'date_from' => $period === 'custom' ? $since->toDateString() : null,
                'date_to' => $period === 'custom' ? $until->toDateString() : null,
            ],
            'periodDays' => $windowDays,
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
            'upsells' => [
                'count' => $upsells,
                'rate' => $totals['confirmed'] > 0
                    ? round(($upsells / $totals['confirmed']) * 100, 1)
                    : null,
            ],
            'bestConfirmHour' => $this->bestConfirmHour($user, $since, $until),
        ]);
    }

    /**
     * The hour of day (0–23) in which this agent confirmed the most orders
     * over the window, from the confirmation status events they made.
     * Null when they confirmed nothing.
     */
    private function bestConfirmHour(User $user, Carbon $since, Carbon $until): ?int
    {
        $hour = OrderStatusEvent::query()->getConnection()->getDriverName() === 'sqlite'
            ? "cast(strftime('%H', created_at) as integer)"
            : 'HOUR(created_at)';

        $row = OrderStatusEvent::where('business_id', $user->business_id)
            ->where('changed_by_user_id', $user->id)
            ->whereIn('to_status', [OrderConfirmationStatus::CONFIRMED->value, OrderConfirmationStatus::CONFIRMED_FOLLOWUP->value])
            ->whereBetween('created_at', [$since->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->selectRaw("{$hour} as hour, count(*) as total")
            ->groupByRaw($hour)
            ->orderByDesc('total')
            ->orderBy('hour')
            ->first();

        return $row === null ? null : (int) $row->hour;
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
