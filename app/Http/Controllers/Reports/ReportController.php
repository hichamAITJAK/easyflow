<?php

namespace App\Http\Controllers\Reports;

use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CommissionLedgerEntry;
use App\Models\CourierSettlement;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = $request->user()->business_id;

        return Inertia::render('reports/index', [
            'filterOptions' => [
                'cities' => Order::query()
                    ->where('business_id', $businessId)
                    ->whereNotNull('customer_city')
                    ->distinct()
                    ->orderBy('customer_city')
                    ->pluck('customer_city'),
                'stores' => Store::query()
                    ->where('business_id', $businessId)
                    ->orderBy('name')
                    ->pluck('name'),
                'couriers' => DeliveryAccount::query()
                    ->where('business_id', $businessId)
                    ->with('courier:id,name')
                    ->get()
                    ->pluck('courier.name')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values(),
                'agents' => User::query()
                    ->where('business_id', $businessId)
                    ->where('role', UserRole::CONFIRMATION_AGENT)
                    ->orderBy('name')
                    ->pluck('name'),
                'confirmationStatuses' => collect(OrderConfirmationStatus::cases())->map(fn ($case) => $case->value),
                'deliveryStatuses' => collect(OrderDeliveryStatus::cases())->map(fn ($case) => $case->value),
                'cancellationReasons' => collect(OrderCancelReason::cases())->map(fn ($case) => $case->description()),
            ],
        ]);
    }

    public function generate(Request $request, PostHogService $posthog): JsonResponse
    {
        $validated = $request->validate([
            'report_id' => ['required', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'city' => ['nullable', 'string'],
            'store' => ['nullable', 'array'],
            'store.*' => ['string'],
            'courier' => ['nullable', 'array'],
            'courier.*' => ['string'],
            'agent' => ['nullable', 'string'],
            'confirmation_status' => ['nullable', 'string'],
            'delivery_status' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
            'include_test_orders' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 20;

        $businessId = $request->user()->business_id;
        $from = $validated['date_from'] ?? null ? Carbon::parse($validated['date_from']) : Carbon::now()->subDays(30)->startOfDay();
        $to = $validated['date_to'] ?? null ? Carbon::parse($validated['date_to'])->endOfDay() : Carbon::now()->endOfDay();
        $includeTestOrders = (bool) ($validated['include_test_orders'] ?? false);

        $filters = [
            'city' => $validated['city'] ?? 'all',
            // Store and courier are multi-select: a list of names, or an
            // empty list meaning "no filter". The other single-value
            // filters keep their 'all' sentinel.
            'store' => $validated['store'] ?? [],
            'courier' => $validated['courier'] ?? [],
            'agent' => $validated['agent'] ?? 'all',
            'confirmation_status' => $validated['confirmation_status'] ?? 'all',
            'delivery_status' => $validated['delivery_status'] ?? 'all',
            'reason' => $validated['reason'] ?? 'all',
        ];

        $result = match ($validated['report_id']) {
            'revenue' => $this->revenue($businessId, $from, $to, $filters, $includeTestOrders, $page, $perPage),
            'settlements' => $this->settlements($businessId, $from, $to, $filters),
            'commissions' => $this->commissions($businessId, $from, $to, $filters),
            'cancellations' => $this->cancellations($businessId, $from, $to, $filters, $includeTestOrders, $page, $perPage),
            'agent-performance' => $this->agentPerformance($businessId, $from, $to, $filters, $includeTestOrders),
            'courier-performance' => $this->courierPerformance($businessId, $from, $to, $filters, $includeTestOrders),
            'store-sales' => $this->storeSales($businessId, $from, $to, $filters, $includeTestOrders),
            'stranded-orders' => $this->strandedOrders($businessId, $filters, $includeTestOrders, $page, $perPage),
            'geography-time' => $this->geographyTime($businessId, $from, $to, $filters, $includeTestOrders),
            default => abort(404),
        };

        $posthog->capture((string) $request->user()->id, 'report_generated', [
            'report_id' => $validated['report_id'],
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            ...$filters,
            'include_test_orders' => $includeTestOrders,
        ]);

        return response()->json($result);
    }

    /**
     * @param  array<string, string|array<int, string>>  $filters
     * @return array{rows: array<int, array<string, mixed>>, chartData: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    private function revenue(int $businessId, Carbon $from, Carbon $to, array $filters, bool $includeTestOrders, int $page, int $perPage): array
    {
        $query = Order::query()
            ->where('business_id', $businessId)
            ->whereBetween('ordered_at', [$from, $to])
            ->when(! $includeTestOrders, fn ($q) => $q->where('is_test', false))
            ->when($filters['store'] !== [], fn ($q) => $q->whereHas('store', fn ($s) => $s->whereIn('name', $filters['store'])))
            ->with('store:id,name');

        $paginated = $query->orderByDesc('ordered_at')->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginated->items())->map(fn (Order $order) => [
            'order' => $order->reference ?? "#{$order->id}",
            'store' => optional($order->store)->name ?? '—',
            'status' => $this->revenueStatus($order),
            'amount' => number_format((float) $order->total_amount, 2),
            'date' => optional($order->ordered_at)->toDateString(),
        ])->all();

        $weeks = $this->weekBuckets($from, $to);
        $chartData = array_map(function (array $bucket) use ($businessId, $filters, $includeTestOrders) {
            $base = Order::query()
                ->where('business_id', $businessId)
                ->whereBetween('ordered_at', [$bucket['from'], $bucket['to']])
                ->when(! $includeTestOrders, fn ($q) => $q->where('is_test', false))
                ->when($filters['store'] !== [], fn ($q) => $q->whereHas('store', fn ($s) => $s->whereIn('name', $filters['store'])));

            return [
                'date' => $bucket['label'],
                'arrived' => (float) (clone $base)->where('delivery_status', OrderDeliveryStatus::DELIVERED)->sum('total_amount'),
                'expected' => (float) (clone $base)->whereIn('delivery_status', [
                    OrderDeliveryStatus::AWAITING_PICKUP, OrderDeliveryStatus::READY_FOR_PICKUP,
                    OrderDeliveryStatus::IN_TRANSIT, OrderDeliveryStatus::OUT_FOR_DELIVERY,
                ])->sum('total_amount'),
                'waiting' => (float) (clone $base)->where('confirmation_status', OrderConfirmationStatus::CONFIRMED)
                    ->whereNull('delivery_status')->sum('total_amount'),
            ];
        }, $weeks);

        return ['rows' => $rows, 'chartData' => $chartData, 'pagination' => $this->paginationMeta($paginated)];
    }

    private function revenueStatus(Order $order): string
    {
        if ($order->delivery_status === OrderDeliveryStatus::DELIVERED) {
            return 'Arrived';
        }

        if (in_array($order->delivery_status, [
            OrderDeliveryStatus::AWAITING_PICKUP, OrderDeliveryStatus::READY_FOR_PICKUP,
            OrderDeliveryStatus::IN_TRANSIT, OrderDeliveryStatus::OUT_FOR_DELIVERY,
        ], true)) {
            return 'Expected';
        }

        if ($order->confirmation_status === OrderConfirmationStatus::CONFIRMED && $order->delivery_status === null) {
            return 'Waiting';
        }

        return 'Out';
    }

    /**
     * @param  array<string, string|array<int, string>>  $filters
     * @return array{rows: array<int, array<string, mixed>>, chartData: array<int, array<string, mixed>>}
     */
    private function settlements(int $businessId, Carbon $from, Carbon $to, array $filters): array
    {
        $settlements = CourierSettlement::query()
            ->where('business_id', $businessId)
            ->whereBetween('period_start', [$from, $to])
            ->with('deliveryAccount.courier:id,name')
            ->when($filters['courier'] !== [], fn ($q) => $q->whereHas('deliveryAccount.courier', fn ($c) => $c->whereIn('name', $filters['courier'])))
            ->get();

        $rows = $settlements->map(fn (CourierSettlement $s) => [
            'courier' => optional(optional($s->deliveryAccount)->courier)->name ?? '—',
            'expected' => number_format((float) $s->expected_amount, 2),
            'settled' => number_format((float) ($s->actual_amount ?? 0), 2),
            'diff' => number_format((float) (($s->actual_amount ?? 0) - $s->expected_amount), 2),
            'status' => ucfirst($s->status),
        ])->all();

        return ['rows' => $rows, 'chartData' => []];
    }

    /**
     * @param  array<string, string|array<int, string>>  $filters
     * @return array{rows: array<int, array<string, mixed>>, chartData: array<int, array<string, mixed>>}
     */
    private function commissions(int $businessId, Carbon $from, Carbon $to, array $filters): array
    {
        $entries = CommissionLedgerEntry::query()
            ->where('business_id', $businessId)
            ->whereBetween('created_at', [$from, $to])
            ->with('user:id,name')
            ->when($filters['agent'] !== 'all', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', $filters['agent'])))
            ->get()
            ->groupBy(fn (CommissionLedgerEntry $e): string => optional($e->user)->name ?? 'Unknown');

        $rows = $entries->map(function ($group, $agentName) {
            $amount = $group->sum('amount');
            $confirmedCount = $group->pluck('order_id')->unique()->count();

            return [
                'agent' => $agentName,
                'confirmed' => $confirmedCount,
                'rate' => $confirmedCount > 0 ? number_format($amount / $confirmedCount, 2).' MAD' : '—',
                'amount' => number_format((float) $amount, 2),
                'invoice' => $group->whereNotNull('invoice_id')->isNotEmpty() ? 'Invoiced' : 'Pending',
            ];
        })->values()->all();

        $chartData = collect($rows)->map(fn ($r) => [
            'agent' => $r['agent'],
            'commission' => (float) str_replace(',', '', $r['amount']),
        ])->all();

        return ['rows' => $rows, 'chartData' => $chartData];
    }

    /**
     * @param  array<string, string|array<int, string>>  $filters
     * @return array{rows: array<int, array<string, mixed>>, chartData: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    private function cancellations(int $businessId, Carbon $from, Carbon $to, array $filters, bool $includeTestOrders, int $page, int $perPage): array
    {
        $baseQuery = fn () => Order::query()
            ->where('business_id', $businessId)
            ->whereBetween('ordered_at', [$from, $to])
            ->when(! $includeTestOrders, fn ($q) => $q->where('is_test', false))
            ->where(function ($q) {
                $q->where('confirmation_status', OrderConfirmationStatus::CANCELLED)
                    ->orWhereIn('delivery_status', [OrderDeliveryStatus::RETURNED_IN_TRANSIT, OrderDeliveryStatus::RETURN_RECEIVED, OrderDeliveryStatus::REFUSED]);
            })
            ->when($filters['store'] !== [], fn ($q) => $q->whereHas('store', fn ($s) => $s->whereIn('name', $filters['store'])))
            ->when($filters['agent'] !== 'all', fn ($q) => $q->whereHas('assignedAgent', fn ($a) => $a->where('name', $filters['agent'])));

        $paginated = $baseQuery()->with(['store:id,name'])->orderByDesc('ordered_at')->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginated->items())->map(fn (Order $order) => [
            'order' => $order->reference ?? "#{$order->id}",
            'store' => optional($order->store)->name ?? '—',
            'reason' => $order->cancellation_reason_code?->description() ?? $order->return_reason_code?->description() ?? '—',
            'stage' => $order->confirmation_status === OrderConfirmationStatus::CANCELLED ? 'Confirmation' : 'Delivery',
            'date' => optional($order->ordered_at)->toDateString(),
        ])->all();

        $chartData = $baseQuery()->get()
            ->groupBy(fn (Order $o) => $o->cancellation_reason_code?->description() ?? $o->return_reason_code?->description() ?? 'Other')
            ->map(fn ($group, $reason) => ['reason' => $reason, 'count' => $group->count()])
            ->values()->all();

        return ['rows' => $rows, 'chartData' => $chartData, 'pagination' => $this->paginationMeta($paginated)];
    }

    /**
     * @param  array<string, string|array<int, string>>  $filters
     * @return array{rows: array<int, array<string, mixed>>, chartData: array<int, array<string, mixed>>}
     */
    private function agentPerformance(int $businessId, Carbon $from, Carbon $to, array $filters, bool $includeTestOrders): array
    {
        $agents = User::query()
            ->where('business_id', $businessId)
            ->where('role', UserRole::CONFIRMATION_AGENT)
            ->when($filters['agent'] !== 'all', fn ($q) => $q->where('name', $filters['agent']))
            ->get();

        $rows = $agents->map(function (User $agent) use ($businessId, $from, $to, $includeTestOrders) {
            $base = Order::query()
                ->where('business_id', $businessId)
                ->where('assigned_agent_id', $agent->id)
                ->whereBetween('ordered_at', [$from, $to])
                ->when(! $includeTestOrders, fn ($q) => $q->where('is_test', false));

            $leads = (clone $base)->count();
            $confirmed = (clone $base)->where('confirmation_status', OrderConfirmationStatus::CONFIRMED)->count();
            $delivered = (clone $base)->where('delivery_status', OrderDeliveryStatus::DELIVERED)->count();
            $returned = (clone $base)->whereIn('delivery_status', [OrderDeliveryStatus::RETURNED_IN_TRANSIT, OrderDeliveryStatus::RETURN_RECEIVED])->count();

            $confirmedRate = $leads > 0 ? round($confirmed / $leads * 100) : 0;
            $deliveredRate = $confirmed > 0 ? round($delivered / $confirmed * 100) : 0;
            $returnRate = $delivered > 0 ? round($returned / max($delivered, 1) * 100) : 0;
            $objective = 85;
            $score = max(0, min(100, round($confirmedRate * 0.6 + $deliveredRate * 0.3 - $returnRate * 0.5 + 20)));

            return [
                'agent' => $agent->name,
                'leads' => $leads,
                'confirmedRate' => $confirmedRate.'%',
                'deliveredRate' => $deliveredRate.'%',
                'returnRate' => $returnRate.'%',
                'objective' => $objective.'%',
                'score' => (string) $score,
                '_confirmedRateRaw' => $confirmedRate,
            ];
        });

        $chartData = $rows->map(fn ($r) => ['agent' => $r['agent'], 'confirmedRate' => $r['_confirmedRateRaw']])->values()->all();
        $rows = $rows->map(fn ($r) => collect($r)->except('_confirmedRateRaw')->all())->values()->all();

        return ['rows' => $rows, 'chartData' => $chartData];
    }

    /**
     * @param  array<string, string|array<int, string>>  $filters
     * @return array{rows: array<int, array<string, mixed>>, chartData: array<int, array<string, mixed>>}
     */
    private function courierPerformance(int $businessId, Carbon $from, Carbon $to, array $filters, bool $includeTestOrders): array
    {
        $accounts = DeliveryAccount::query()
            ->where('business_id', $businessId)
            ->with('courier:id,name')
            ->when($filters['courier'] !== [], fn ($q) => $q->whereHas('courier', fn ($c) => $c->whereIn('name', $filters['courier'])))
            ->get()
            ->groupBy(fn (DeliveryAccount $a): string => optional($a->courier)->name ?? 'Unknown');

        $rows = $accounts->map(function ($accountGroup, $courierName) use ($businessId, $from, $to, $includeTestOrders) {
            $accountIds = $accountGroup->pluck('id');

            $base = Order::query()
                ->where('business_id', $businessId)
                ->whereIn('delivery_account_id', $accountIds)
                ->whereBetween('ordered_at', [$from, $to])
                ->when(! $includeTestOrders, fn ($q) => $q->where('is_test', false));

            $shipped = (clone $base)->whereNotNull('shipped_at')->count();
            $delivered = (clone $base)->where('delivery_status', OrderDeliveryStatus::DELIVERED)->count();
            $returned = (clone $base)->whereIn('delivery_status', [OrderDeliveryStatus::RETURNED_IN_TRANSIT, OrderDeliveryStatus::RETURN_RECEIVED])->count();
            $scanned = (clone $base)->whereNotNull('ready_for_pickup_at')->count();

            $avgDays = (clone $base)->where('delivery_status', OrderDeliveryStatus::DELIVERED)
                ->whereNotNull('shipped_at')
                ->get(['shipped_at', 'updated_at'])
                ->avg(fn (Order $o) => $o->shipped_at?->diffInDays($o->updated_at) ?? 0);

            $deliveredRate = $shipped > 0 ? round($delivered / $shipped * 100) : 0;

            return [
                'courier' => $courierName,
                'shipped' => $shipped,
                'delivered' => $delivered,
                'returned' => $returned,
                'scanned' => $scanned,
                'avgTime' => $avgDays ? round($avgDays, 1).' days' : '—',
                '_deliveredRateRaw' => $deliveredRate,
            ];
        });

        $chartData = $rows->map(fn ($r) => ['courier' => $r['courier'], 'deliveredRate' => $r['_deliveredRateRaw']])->values()->all();
        $rows = $rows->map(fn ($r) => collect($r)->except('_deliveredRateRaw')->all())->values()->all();

        return ['rows' => $rows, 'chartData' => $chartData];
    }

    /**
     * @param  array<string, string|array<int, string>>  $filters
     * @return array{rows: array<int, array<string, mixed>>, chartData: array<int, array<string, mixed>>}
     */
    private function storeSales(int $businessId, Carbon $from, Carbon $to, array $filters, bool $includeTestOrders): array
    {
        $stores = Store::query()
            ->where('business_id', $businessId)
            ->when($filters['store'] !== [], fn ($q) => $q->whereIn('name', $filters['store']))
            ->get();

        $rows = $stores->map(function (Store $store) use ($businessId, $from, $to, $includeTestOrders) {
            $base = Order::query()
                ->where('business_id', $businessId)
                ->where('store_id', $store->id)
                ->whereBetween('ordered_at', [$from, $to])
                ->when(! $includeTestOrders, fn ($q) => $q->where('is_test', false));

            $orders = (clone $base)->count();
            $confirmed = (clone $base)->where('confirmation_status', OrderConfirmationStatus::CONFIRMED)->count();
            $delivered = (clone $base)->where('delivery_status', OrderDeliveryStatus::DELIVERED)->count();
            $revenue = (clone $base)->where('delivery_status', OrderDeliveryStatus::DELIVERED)->sum('total_amount');

            $bestProduct = OrderItem::query()
                ->where('business_id', $businessId)
                ->whereHas('order', fn ($q) => $q->where('store_id', $store->id)->whereBetween('ordered_at', [$from, $to]))
                ->select('product_name_snapshot', DB::raw('SUM(quantity) as total_qty'))
                ->groupBy('product_name_snapshot')
                ->orderByDesc('total_qty')
                ->first();

            return [
                'store' => $store->name,
                'orders' => $orders,
                'confirmedRate' => ($orders > 0 ? round($confirmed / $orders * 100) : 0).'%',
                'deliveredRate' => ($confirmed > 0 ? round($delivered / $confirmed * 100) : 0).'%',
                'bestProduct' => $bestProduct === null ? '—' : $bestProduct->product_name_snapshot,
                'revenue' => number_format((float) $revenue, 2),
                '_revenueRaw' => (float) $revenue,
            ];
        });

        $chartData = $rows->map(fn ($r) => ['store' => $r['store'], 'revenue' => $r['_revenueRaw']])->values()->all();
        $rows = $rows->map(fn ($r) => collect($r)->except('_revenueRaw')->all())->values()->all();

        return ['rows' => $rows, 'chartData' => $chartData];
    }

    /**
     * @param  array<string, string|array<int, string>>  $filters
     * @return array{rows: array<int, array<string, mixed>>, chartData: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    private function strandedOrders(int $businessId, array $filters, bool $includeTestOrders, int $page, int $perPage): array
    {
        $now = Carbon::now();
        $strandedSince = $now->copy()->subDays(3);

        $query = Order::query()
            ->where('business_id', $businessId)
            ->when(! $includeTestOrders, fn ($q) => $q->where('is_test', false))
            ->where(function ($q) {
                $q->where('confirmation_status', OrderConfirmationStatus::NEW)
                    ->orWhere(function ($q2) {
                        $q2->where('confirmation_status', OrderConfirmationStatus::CONFIRMED)->whereNull('delivery_status');
                    })
                    ->orWhere('delivery_status', OrderDeliveryStatus::IN_TRANSIT);
            })
            ->where(function ($q) use ($strandedSince) {
                $q->where('ordered_at', '<=', $strandedSince)
                    ->orWhere('shipped_at', '<=', $strandedSince);
            })
            ->when($filters['store'] !== [], fn ($q) => $q->whereHas('store', fn ($s) => $s->whereIn('name', $filters['store'])))
            ->with(['store:id,name', 'assignedAgent:id,name', 'deliveryAccount.courier:id,name'])
            ->orderBy('ordered_at');

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginated->items())->map(function (Order $order) use ($now) {
            $stage = match (true) {
                $order->confirmation_status === OrderConfirmationStatus::NEW => 'Awaiting confirmation',
                $order->confirmation_status === OrderConfirmationStatus::CONFIRMED && $order->delivery_status === null => 'Awaiting shipment',
                $order->delivery_status === OrderDeliveryStatus::IN_TRANSIT => 'In transit',
                default => 'Unknown',
            };

            $reference = $order->shipped_at ?? $order->ordered_at ?? $order->created_at;
            $days = $reference ? $reference->diffInDays($now) : 0;

            $assignee = optional($order->assignedAgent)->name
                ?? optional(optional($order->deliveryAccount)->courier)->name
                ?? 'Unassigned';

            return [
                'order' => $order->reference ?? "#{$order->id}",
                'store' => optional($order->store)->name ?? '—',
                'stage' => $stage,
                'days' => $days,
                'assignee' => $assignee,
            ];
        })->all();

        return ['rows' => $rows, 'chartData' => [], 'pagination' => $this->paginationMeta($paginated)];
    }

    /**
     * @param  array<string, string|array<int, string>>  $filters
     * @return array{rows: array<int, array<string, mixed>>, chartData: array<int, array<string, mixed>>}
     */
    private function geographyTime(int $businessId, Carbon $from, Carbon $to, array $filters, bool $includeTestOrders): array
    {
        $query = Order::query()
            ->where('business_id', $businessId)
            ->whereBetween('ordered_at', [$from, $to])
            ->when(! $includeTestOrders, fn ($q) => $q->where('is_test', false))
            ->when($filters['city'] !== 'all', fn ($q) => $q->where('customer_city', $filters['city']));

        $byCity = (clone $query)->get()->groupBy(fn (Order $o): string => $o->customer_city ?? 'Unknown');

        $rows = $byCity->map(function ($group, $city) {
            $orders = $group->count();
            $confirmed = $group->where('confirmation_status', OrderConfirmationStatus::CONFIRMED)->count();
            $delivered = $group->where('delivery_status', OrderDeliveryStatus::DELIVERED)->count();

            $peakHour = $group->groupBy(fn (Order $o): string => optional($o->ordered_at)->format('H') ?? 'Unknown')
                ->sortByDesc(fn ($g) => $g->count())
                ->keys()
                ->first();

            return [
                'city' => $city,
                'orders' => $orders,
                'confirmedRate' => ($orders > 0 ? round($confirmed / $orders * 100) : 0).'%',
                'deliveredRate' => ($confirmed > 0 ? round($delivered / $confirmed * 100) : 0).'%',
                'peakTime' => $peakHour !== null ? sprintf('%02d:00-%02d:00', (int) $peakHour, (int) $peakHour + 4) : '—',
                '_orders' => $orders,
            ];
        })->sortByDesc('_orders')->map(fn ($r) => collect($r)->except('_orders')->all())->values()->all();

        $allOrders = (clone $query)->get(['ordered_at', 'confirmation_status', 'delivery_status']);

        $chartData = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $inHour = $allOrders->filter(fn (Order $o) => $o->ordered_at && (int) $o->ordered_at->format('H') === $hour);
            $total = $inHour->count();
            $confirmed = $inHour->where('confirmation_status', OrderConfirmationStatus::CONFIRMED)->count();
            $delivered = $inHour->where('delivery_status', OrderDeliveryStatus::DELIVERED)->count();

            $chartData[] = [
                'hour' => sprintf('%02d:00', $hour),
                'confirmedRate' => $total > 0 ? round($confirmed / $total * 100) : 0,
                'deliveredRate' => $confirmed > 0 ? round($delivered / $confirmed * 100) : 0,
            ];
        }

        return ['rows' => $rows, 'chartData' => $chartData];
    }

    /**
     * @param  LengthAwarePaginator<int, Order>  $paginator
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /** @return array<int, array{label: string, from: Carbon, to: Carbon}> */
    private function weekBuckets(Carbon $from, Carbon $to): array
    {
        $buckets = [];
        $cursor = $from->copy()->startOfDay();
        $index = 1;

        while ($cursor->lte($to) && $index <= 8) {
            $bucketEnd = $cursor->copy()->addDays(6)->endOfDay();
            $buckets[] = [
                'label' => 'W'.$index,
                'from' => $cursor->copy(),
                'to' => $bucketEnd->greaterThan($to) ? $to->copy() : $bucketEnd,
            ];
            $cursor->addDays(7);
            $index++;
        }

        if (empty($buckets)) {
            $buckets[] = ['label' => 'W1', 'from' => $from, 'to' => $to];
        }

        return $buckets;
    }
}
