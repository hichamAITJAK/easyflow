<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\CommissionLedgerEntry;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\Scopes\BusinessScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform-wide dashboard: every business's orders, parcels and team
 * in one view, filterable by business and period.
 *
 * Read-only. Everything here is aggregated live from the existing tables
 * (orders, delivery_accounts, order_status_events, commission_ledger_
 * entries, users) — no stats table of its own, no new columns. The super
 * admin has no business_id, so BusinessScope is a no-op for them; it is
 * still removed explicitly so the intent to read across tenants is
 * visible, not accidental.
 */
class DashboardController extends Controller
{
    private const DEFAULT_PERIOD = '30d';

    private const MAX_CUSTOM_RANGE_DAYS = 366;

    /** Confirmation statuses that count as "still being worked" by an agent. */
    private const IN_PROGRESS = [
        OrderConfirmationStatus::NEW,
        OrderConfirmationStatus::ASSIGNED,
        OrderConfirmationStatus::CALLBACK,
        OrderConfirmationStatus::VOICEMAIL,
        OrderConfirmationStatus::NO_ANSWER,
        OrderConfirmationStatus::BUSY,
        OrderConfirmationStatus::WHATSAPP_SENT,
    ];

    private const CONFIRMED = [
        OrderConfirmationStatus::CONFIRMED,
        OrderConfirmationStatus::CONFIRMED_FOLLOWUP,
        OrderConfirmationStatus::SUBMITTED_TO_COURIER,
    ];

    private const RETURNED = [
        OrderDeliveryStatus::REFUSED,
        OrderDeliveryStatus::RETURNED_IN_TRANSIT,
        OrderDeliveryStatus::RETURN_RECEIVED,
    ];

    public function index(Request $request): Response
    {
        $period = $request->string('period')->toString() ?: self::DEFAULT_PERIOD;
        [$since, $until] = $this->resolvePeriod($period, $request);

        $businesses = Business::orderBy('name')->get(['id', 'name']);
        $selected = $request->integer('business') ?: null;

        if ($selected !== null && ! $businesses->contains('id', $selected)) {
            $selected = null;
        }

        $businessIds = $selected === null ? $businesses->pluck('id')->all() : [$selected];
        $names = $businesses->pluck('name', 'id');

        $rows = $this->businessRows($businessIds, $since, $until);

        return Inertia::render('super-admin/dashboard', [
            'filters' => [
                'businesses' => $businesses,
                'business' => $selected === null ? null : (string) $selected,
                'period' => $period,
                'date_from' => $period === 'custom' ? $since->toDateString() : null,
                'date_to' => $period === 'custom' ? $until->toDateString() : null,
                'rangeLabel' => $this->rangeLabel($since, $until),
            ],
            'trend' => $this->trend($businessIds, $since, $until),
            'businesses' => [
                'totals' => [
                    'inProgress' => (int) $rows->sum('in_progress'),
                    'confirmedPending' => max(0, (int) $rows->sum('confirmed') - (int) $rows->sum('delivered') - (int) $rows->sum('returned')),
                    'delivered' => (int) $rows->sum('delivered'),
                    'returned' => (int) $rows->sum('returned'),
                ],
                'rows' => $rows->map(fn (object $row) => [
                    'id' => (int) $row->business_id,
                    'name' => $names[$row->business_id] ?? '—',
                    'initials' => $this->initials($names[$row->business_id] ?? '—'),
                    'orders' => (int) $row->orders,
                    'inProgress' => [(int) $row->in_progress, $this->pct((int) $row->in_progress, (int) $row->orders)],
                    'confirmed' => [(int) $row->confirmed, $this->pct((int) $row->confirmed, (int) $row->orders)],
                    'delivered' => [(int) $row->delivered, $this->pct((int) $row->delivered, (int) $row->confirmed)],
                    'returned' => [(int) $row->returned, $this->pct((int) $row->returned, (int) $row->confirmed)],
                ])->values()->all(),
            ],
            'parcels' => $this->parcels($businessIds, $names, $rows, $since, $until),
            'team' => $this->team($businessIds, $names, $since, $until),
        ]);
    }

    /**
     * Base query: real orders of the selected businesses created in the window.
     *
     * @param  array<int, int>  $businessIds
     * @return Builder<Order>
     */
    private function orders(array $businessIds, Carbon $since, Carbon $until): Builder
    {
        return Order::withoutGlobalScope(BusinessScope::class)
            ->whereIn('orders.business_id', $businessIds)
            ->where('orders.is_test', false)
            ->whereBetween('orders.created_at', [$since->copy()->startOfDay(), $until->copy()->endOfDay()]);
    }

    /**
     * One point per hour for a single day, per day otherwise. Every bucket
     * of the window is present, zero-filled, so the line never skips a
     * quiet day.
     *
     * @param  array<int, int>  $businessIds
     * @return array{totalOrders: int, buckets: list<array{label: string, value: int}>}
     */
    private function trend(array $businessIds, Carbon $since, Carbon $until): array
    {
        $hourly = $since->isSameDay($until);
        // SQLite (tests) has no HOUR(); both drivers share DATE().
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $expr = match (true) {
            $hourly && $sqlite => "cast(strftime('%H', orders.created_at) as integer)",
            $hourly => 'HOUR(orders.created_at)',
            default => 'DATE(orders.created_at)',
        };

        $counts = $this->orders($businessIds, $since, $until)
            ->toBase()
            ->selectRaw("{$expr} as bucket, count(*) as aggregate")
            ->groupBy('bucket')
            ->pluck('aggregate', 'bucket');

        $buckets = [];

        if ($hourly) {
            foreach (range(0, 23) as $hour) {
                $buckets[] = ['label' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT).'h', 'value' => (int) ($counts[$hour] ?? 0)];
            }
        } else {
            for ($day = $since->copy(); $day->lessThanOrEqualTo($until); $day->addDay()) {
                $buckets[] = ['label' => $day->format('M j'), 'value' => (int) ($counts[$day->toDateString()] ?? 0)];
            }
        }

        return [
            'totalOrders' => (int) $counts->sum(),
            'buckets' => $buckets,
        ];
    }

    /**
     * Per-business funnel counts, orders desc.
     *
     * @param  array<int, int>  $businessIds
     * @return Collection<int, object>
     */
    private function businessRows(array $businessIds, Carbon $since, Carbon $until): Collection
    {
        $in = fn (array $cases): string => implode(',', array_map(fn ($case) => "'{$case->value}'", $cases));

        return $this->orders($businessIds, $since, $until)
            ->toBase()
            ->selectRaw('orders.business_id, count(*) as orders')
            ->selectRaw('sum(case when orders.confirmation_status in ('.$in(self::IN_PROGRESS).') then 1 else 0 end) as in_progress')
            ->selectRaw('sum(case when orders.confirmation_status in ('.$in(self::CONFIRMED).') then 1 else 0 end) as confirmed')
            ->selectRaw("sum(case when orders.delivery_status = '".OrderDeliveryStatus::DELIVERED->value."' then 1 else 0 end) as delivered")
            ->selectRaw('sum(case when orders.delivery_status in ('.$in(self::RETURNED).') then 1 else 0 end) as returned')
            ->groupBy('orders.business_id')
            ->orderByDesc('orders')
            ->get();
    }

    /**
     * The shipment pipeline per business and per courier. A business's
     * parcels are its confirmed orders; a courier's are the orders it was
     * handed, so the courier tab sums to the parcels that actually left.
     *
     * @param  array<int, int>  $businessIds
     * @param  Collection<int, string>  $names
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function parcels(array $businessIds, Collection $names, Collection $rows, Carbon $since, Carbon $until): array
    {
        $byAccount = $this->orders($businessIds, $since, $until)
            ->whereNotNull('orders.delivery_account_id')
            ->toBase()
            ->selectRaw('orders.business_id, orders.delivery_account_id, count(*) as parcels')
            ->selectRaw("sum(case when orders.delivery_status = '".OrderDeliveryStatus::DELIVERED->value."' then 1 else 0 end) as delivered")
            ->selectRaw('sum(case when orders.delivery_status in ('.implode(',', array_map(fn ($case) => "'{$case->value}'", self::RETURNED)).') then 1 else 0 end) as returned')
            ->groupBy('orders.business_id', 'orders.delivery_account_id')
            ->get();

        $accounts = DeliveryAccount::withoutGlobalScope(BusinessScope::class)
            ->with('courier:id,name,slug,logo')
            ->whereIn('id', $byAccount->pluck('delivery_account_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $courierOf = fn (int $accountId): ?array => ($courier = $accounts[$accountId]?->courier) === null ? null : [
            'id' => $courier->id,
            'name' => $courier->name,
            'logoUrl' => $courier->logo ?: '/assets/images/'.$courier->slug.'_icon.png',
        ];

        $byBusiness = $rows->map(function (object $row) use ($byAccount, $courierOf, $names) {
            $parcels = (int) $row->confirmed;
            $delivered = (int) $row->delivered;
            $returned = (int) $row->returned;
            $shipped = max(0, $parcels - $delivered - $returned);

            $couriers = $byAccount->where('business_id', $row->business_id)
                ->map(fn (object $account) => $courierOf((int) $account->delivery_account_id))
                ->filter()
                ->unique('id')
                ->map(fn (array $courier) => ['name' => $courier['name'], 'logoUrl' => $courier['logoUrl']])
                ->values()
                ->all();

            return [
                'id' => (int) $row->business_id,
                'name' => $names[$row->business_id] ?? '—',
                'initials' => $this->initials($names[$row->business_id] ?? '—'),
                'parcels' => $parcels,
                'couriers' => $couriers,
                'shipped' => [$shipped, $this->pct($shipped, $parcels)],
                'delivered' => [$delivered, $this->pct($delivered, $parcels)],
                'returned' => [$returned, $this->pct($returned, $parcels)],
            ];
        })->values()->all();

        $byCourier = $byAccount
            ->groupBy(fn (object $account) => $courierOf((int) $account->delivery_account_id)['id'] ?? 0)
            ->except([0])
            ->map(function (Collection $group) use ($courierOf, $names) {
                $courier = $courierOf((int) $group->first()->delivery_account_id);
                $parcels = (int) $group->sum('parcels');
                $delivered = (int) $group->sum('delivered');
                $returned = (int) $group->sum('returned');
                $shipped = max(0, $parcels - $delivered - $returned);

                return [
                    'name' => $courier['name'],
                    'logoUrl' => $courier['logoUrl'],
                    'businesses' => $group->pluck('business_id')->unique()->map(fn ($id) => $names[$id] ?? '—')->values()->all(),
                    'parcels' => $parcels,
                    'shipped' => [$shipped, $this->pct($shipped, $parcels)],
                    'delivered' => [$delivered, $this->pct($delivered, $parcels)],
                    'returned' => [$returned, $this->pct($returned, $parcels)],
                ];
            })
            ->sortByDesc('parcels')
            ->values()
            ->all();

        return [
            'total' => (int) $rows->sum('confirmed'),
            'byBusiness' => $byBusiness,
            'byCourier' => $byCourier,
        ];
    }

    /**
     * Every confirmation and fulfilment account of the selected businesses
     * with what it did in the window. One row per account: the same person
     * with a login in two businesses is two rows, never merged.
     *
     * @param  array<int, int>  $businessIds
     * @param  Collection<int, string>  $names
     * @return array{confirmers: list<array<string, mixed>>, fulfilment: list<array<string, mixed>>}
     */
    private function team(array $businessIds, Collection $names, Carbon $since, Carbon $until): array
    {
        $users = User::withoutGlobalScope(BusinessScope::class)
            ->whereIn('business_id', $businessIds)
            ->whereIn('role', [UserRole::CONFIRMATION_AGENT, UserRole::FULFILMENT_AGENT])
            ->orderBy('name')
            ->get(['id', 'name', 'business_id', 'role']);

        $userIds = $users->pluck('id')->all();
        $window = [$since->copy()->startOfDay(), $until->copy()->endOfDay()];

        $byAgent = $this->orders($businessIds, $since, $until)
            ->whereIn('orders.assigned_agent_id', $userIds)
            ->toBase()
            ->selectRaw('orders.assigned_agent_id as user_id, count(*) as orders')
            ->selectRaw('sum(case when orders.confirmation_status in ('.implode(',', array_map(fn ($case) => "'{$case->value}'", self::CONFIRMED)).') then 1 else 0 end) as confirmed')
            ->selectRaw("sum(case when orders.delivery_status = '".OrderDeliveryStatus::DELIVERED->value."' then 1 else 0 end) as delivered")
            ->groupBy('orders.assigned_agent_id')
            ->get()
            ->keyBy('user_id');

        // A warehouse agent's output is the parcels they scanned as ready,
        // read from the audit trail that every scan writes.
        $scans = OrderStatusEvent::withoutGlobalScope(BusinessScope::class)
            ->whereIn('changed_by_user_id', $userIds)
            ->where('to_status', OrderDeliveryStatus::READY_FOR_PICKUP->value)
            ->whereBetween('created_at', $window)
            ->toBase()
            ->selectRaw('changed_by_user_id as user_id, count(*) as parcels')
            ->groupBy('changed_by_user_id')
            ->pluck('parcels', 'user_id');

        $commissions = CommissionLedgerEntry::withoutGlobalScope(BusinessScope::class)
            ->whereIn('user_id', $userIds)
            ->whereBetween('created_at', $window)
            ->toBase()
            ->selectRaw('user_id, sum(amount) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $base = fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'initials' => $this->initials($user->name),
            'business' => $names[$user->business_id] ?? '—',
            'commissionsMad' => (int) round((float) ($commissions[$user->id] ?? 0)),
        ];

        $confirmers = $users->where('role', UserRole::CONFIRMATION_AGENT)
            ->map(function (User $user) use ($base, $byAgent) {
                $row = $byAgent[$user->id] ?? null;
                $orders = (int) ($row->orders ?? 0);
                $confirmed = (int) ($row->confirmed ?? 0);
                $delivered = (int) ($row->delivered ?? 0);

                return [
                    ...$base($user),
                    'orders' => $orders,
                    'confirmed' => [$confirmed, $this->pct($confirmed, $orders)],
                    'delivered' => [$delivered, $this->pct($delivered, $confirmed)],
                ];
            });

        $fulfilment = $users->where('role', UserRole::FULFILMENT_AGENT)
            ->map(fn (User $user) => [
                ...$base($user),
                'parcels' => (int) ($scans[$user->id] ?? 0),
            ]);

        $rank = fn (Collection $rows, string $activity) => $rows
            ->sortBy([['commissionsMad', 'desc'], [$activity, 'desc'], ['name', 'asc']])
            ->values()
            ->all();

        return [
            'confirmers' => $rank($confirmers, 'orders'),
            'fulfilment' => $rank($fulfilment, 'parcels'),
        ];
    }

    private function pct(int $count, int $base): float
    {
        return $base > 0 ? round($count / $base * 100, 1) : 0.0;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = implode('', array_map(fn (string $part) => mb_substr($part, 0, 1), array_slice($parts, 0, 2)));

        return mb_strtoupper($initials);
    }

    private function rangeLabel(Carbon $since, Carbon $until): string
    {
        if ($since->isSameDay($until)) {
            return $since->format('M j, Y');
        }

        return $since->format('M j').' – '.$until->format('M j, Y');
    }

    /**
     * Same presets and custom-range rules as the tenant dashboard, so a
     * period means the same thing on both screens.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(string $period, Request $request): array
    {
        $today = Carbon::today();

        if ($period === 'custom') {
            $from = $this->parseDate($request->string('date_from')->toString());
            $to = $this->parseDate($request->string('date_to')->toString());

            if ($from !== null && $to !== null) {
                if ($from->greaterThan($to)) {
                    [$from, $to] = [$to, $from];
                }

                if ($to->greaterThan($today)) {
                    $to = $today->copy();
                }

                if ($from->lessThanOrEqualTo($to)) {
                    if ($from->diffInDays($to) + 1 > self::MAX_CUSTOM_RANGE_DAYS) {
                        $from = $to->copy()->subDays(self::MAX_CUSTOM_RANGE_DAYS - 1);
                    }

                    return [$from, $to];
                }
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
            default => [$today->copy()->subDays(29), $today],
        };
    }

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
}
