<?php

namespace App\Http\Controllers\Delivery;

use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Http\Controllers\Controller;
use App\Models\DeliveryCashSettlement;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\User;
use App\Services\Operations\Orders\OrderService;
use App\Support\TrackingNumberExtractor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The delivery guy's workspace, built like the warehouse one: scan a
 * label (or type its number), see the parcel, tap what happened to it.
 * Every change goes through OrderService::updateDeliveryStatus() with him
 * as the actor, so the parcel history, the stats and everyone's pay follow
 * exactly as they do for a courier.
 *
 * Only his own parcels resolve — the ones a confirmer handed to him.
 */
class DeliveryController extends Controller
{
    private const UNDO_MINUTES = 5;

    /** What the guy may do to a parcel, by where it stands. */
    private const ACTIONS = [
        'ready_for_pickup' => ['out_for_delivery'],
        'out_for_delivery' => ['delivered', 'delivery_attempt_failed', 'postponed', 'refused', 'returned_in_transit'],
        'postponed' => ['delivered', 'delivery_attempt_failed', 'postponed', 'refused', 'returned_in_transit'],
        'delivery_attempt_failed' => ['delivered', 'delivery_attempt_failed', 'postponed', 'refused', 'returned_in_transit'],
    ];

    /** Why it did not go through; the return reason the ledger understands. */
    private const REASONS = [
        'no_answer' => OrderReturnReason::CLIENT_UNREACHABLE_AT_DELIVERY,
        'client_absent' => OrderReturnReason::CLIENT_UNREACHABLE_AT_DELIVERY,
        'wrong_address' => OrderReturnReason::WRONG_ADDRESS,
        'client_refused' => OrderReturnReason::CLIENT_REFUSED,
        'payment_issue' => OrderReturnReason::PAYMENT_ISSUE,
        'other' => OrderReturnReason::OTHER,
    ];

    private const REASON_LABELS = [
        'no_answer' => 'No answer',
        'client_absent' => 'Client absent',
        'wrong_address' => 'Wrong address',
        'client_refused' => 'Client refused',
        'payment_issue' => 'Could not pay',
        'other' => 'Other',
    ];

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): Response
    {
        return Inertia::render('delivery/index', [
            'summary' => $this->summaryFor($request->user()),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->summaryFor($request->user()));
    }

    /**
     * Read-only preview of a scanned label: the parcel and what can be
     * done with it. Nothing changes until apply().
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate(['qr_value' => ['required', 'string']]);

        $order = $this->findParcel($request->user(), $data['qr_value']);

        return response()->json([
            'order' => $this->payload($order),
            'actions' => $this->actionsFor($order),
        ]);
    }

    /**
     * Commit what happened to the parcel the guy just scanned. The action
     * is checked against the parcel's CURRENT status, never trusted from
     * the client alone. Reasons and dates are kept on the history row.
     */
    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'action' => ['required', Rule::in(['out_for_delivery', 'delivered', 'delivery_attempt_failed', 'postponed', 'refused', 'returned_in_transit'])],
            'reason' => ['nullable', Rule::in(array_keys(self::REASONS))],
            'postponed_until' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->ownParcel($user, (int) $data['order_id']);

        if (! in_array($data['action'], $this->actionsFor($order), true)) {
            throw ValidationException::withMessages([
                'action' => __("This parcel can't be marked like that right now."),
            ]);
        }

        $status = OrderDeliveryStatus::from($data['action']);
        $reason = $data['reason'] ?? null;

        if ($status === OrderDeliveryStatus::POSTPONED && empty($data['postponed_until'])) {
            throw ValidationException::withMessages(['postponed_until' => __('Pick the new delivery date.')]);
        }

        if (in_array($status, [OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED, OrderDeliveryStatus::RETURNED_IN_TRANSIT], true) && $reason === null) {
            throw ValidationException::withMessages(['reason' => __('Say why.')]);
        }

        $order = $this->orders->updateDeliveryStatus(
            $order,
            $status,
            $user,
            $status === OrderDeliveryStatus::RETURNED_IN_TRANSIT ? self::REASONS[$reason] : null,
        );

        $this->annotate($order, implode(' · ', array_filter([
            $reason !== null ? __(self::REASON_LABELS[$reason]) : null,
            $status === OrderDeliveryStatus::POSTPONED
                ? __('Postponed to :date', ['date' => Carbon::parse($data['postponed_until'])->toDateString()])
                : null,
            $data['note'] ?? null,
        ])));

        return response()->json([
            'order' => $this->payload($order->fresh()),
            'actions' => $this->actionsFor($order),
            'summary' => $this->summaryFor($user),
        ]);
    }

    /**
     * Today's changes this guy made, newest first. A change he has since
     * undone is hidden (its reversal row exists, matched the same way the
     * warehouse log does it).
     */
    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();

        $events = OrderStatusEvent::query()
            ->with('order:id,reference,courier_tracking_number,customer_name,customer_city,total_amount')
            ->where('business_id', $user->business_id)
            ->where('changed_by_user_id', $user->id)
            ->whereIn('to_status', array_keys(array_merge(...array_map(fn ($list) => array_flip($list), array_values(self::ACTIONS)))))
            ->whereDate('created_at', now()->toDateString())
            ->whereNotExists(function ($query) use ($user) {
                $query->selectRaw('1')
                    ->from('order_status_events as reversal')
                    ->whereColumn('reversal.order_id', 'order_status_events.order_id')
                    ->whereColumn('reversal.from_status', 'order_status_events.to_status')
                    ->whereColumn('reversal.to_status', 'order_status_events.from_status')
                    ->whereColumn('reversal.id', '>', 'order_status_events.id')
                    ->where('reversal.changed_by_user_id', $user->id);
            })
            // …and the undo row itself: the mirror of an earlier change.
            ->whereNotExists(function ($query) use ($user) {
                $query->selectRaw('1')
                    ->from('order_status_events as original')
                    ->whereColumn('original.order_id', 'order_status_events.order_id')
                    ->whereColumn('original.to_status', 'order_status_events.from_status')
                    ->whereColumn('original.from_status', 'order_status_events.to_status')
                    ->whereColumn('original.id', '<', 'order_status_events.id')
                    ->where('original.changed_by_user_id', $user->id);
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->each(fn (OrderStatusEvent $event) => $event->order?->makeVisible(['customer_name']));

        $undoFrom = now()->subMinutes(self::UNDO_MINUTES);

        return response()->json([
            'events' => $events->map(fn (OrderStatusEvent $event) => [
                'id' => $event->id,
                'order_id' => $event->order_id,
                'reference' => $event->order?->reference,
                'tracking_number' => $event->order?->courier_tracking_number,
                'customer_name' => $event->order?->customer_name,
                'customer_city' => $event->order?->customer_city,
                'amount' => $event->order?->total_amount,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'note' => $event->note,
                'created_at' => $event->created_at,
                // Money changed hands on a delivery: that one is not undone
                // with a tap, the admin sorts it out.
                'can_undo' => $event->to_status !== OrderDeliveryStatus::DELIVERED->value
                    && $event->created_at !== null
                    && $event->created_at->greaterThan($undoFrom),
            ]),
        ]);
    }

    /**
     * Take back the last thing done to a parcel, within a short window:
     * a wrong tap, not a rewrite of the day.
     */
    public function undo(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['event_id' => ['required', 'integer']]);

        $event = OrderStatusEvent::query()
            ->where('id', $data['event_id'])
            ->where('business_id', $user->business_id)
            ->where('changed_by_user_id', $user->id)
            ->first();

        if (
            ! $event
            || $event->created_at === null
            || $event->created_at->lessThan(now()->subMinutes(self::UNDO_MINUTES))
            || $event->to_status === OrderDeliveryStatus::DELIVERED->value
            || $event->from_status === null
        ) {
            throw ValidationException::withMessages(['event_id' => __('This can no longer be undone.')]);
        }

        $order = $this->ownParcel($user, $event->order_id);

        if ($order->delivery_status?->value !== $event->to_status) {
            throw ValidationException::withMessages(['event_id' => __("This parcel's status has changed since — it can no longer be undone.")]);
        }

        $revertTo = OrderDeliveryStatus::from($event->from_status);

        $order = $this->orders->updateDeliveryStatus(
            $order,
            $revertTo,
            $user,
            $revertTo === OrderDeliveryStatus::RETURNED_IN_TRANSIT ? OrderReturnReason::OTHER : null,
        );
        $this->annotate($order, __('Undone'));

        return response()->json(['order' => $this->payload($order), 'summary' => $this->summaryFor($user)]);
    }

    /**
     * The COD cash he is holding: delivered parcels not yet handed over,
     * plus the last hand-over for reference.
     */
    public function cash(Request $request): JsonResponse
    {
        $user = $request->user();

        $parcels = $this->owedParcels($user)
            ->orderByDesc('updated_at')
            ->get(['id', 'reference', 'courier_tracking_number', 'customer_name', 'customer_city', 'total_amount', 'updated_at'])
            ->each->makeVisible(['customer_name']);

        $last = DeliveryCashSettlement::where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return response()->json([
            'owed' => round((float) $parcels->sum('total_amount'), 2),
            'parcels' => $parcels->map(fn (Order $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'tracking_number' => $order->courier_tracking_number,
                'customer_name' => $order->customer_name,
                'customer_city' => $order->customer_city,
                'amount' => $order->total_amount,
                'delivered_at' => $order->updated_at,
            ])->values(),
            'last_settlement' => $last === null ? null : [
                'amount' => $last->amount,
                'parcels_count' => $last->parcels_count,
                'created_at' => $last->created_at,
            ],
        ]);
    }

    /**
     * @return array{to_collect: int, on_road: int, delivered_today: int, cash_owed: float, city: string|null}
     */
    private function summaryFor(User $user): array
    {
        $mine = fn () => Order::where('business_id', $user->business_id)->where('delivery_guy_id', $user->id);

        return [
            'to_collect' => $mine()->where('delivery_status', OrderDeliveryStatus::READY_FOR_PICKUP)->count(),
            'on_road' => $mine()->whereIn('delivery_status', [
                OrderDeliveryStatus::OUT_FOR_DELIVERY,
                OrderDeliveryStatus::POSTPONED,
                OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED,
            ])->count(),
            'delivered_today' => OrderStatusEvent::where('business_id', $user->business_id)
                ->where('changed_by_user_id', $user->id)
                ->where('to_status', OrderDeliveryStatus::DELIVERED->value)
                ->whereDate('created_at', now()->toDateString())
                ->count(),
            'cash_owed' => round((float) $this->owedParcels($user)->sum('total_amount'), 2),
            'city' => $user->delivery_city,
        ];
    }

    /** @return Builder<Order> */
    private function owedParcels(User $user)
    {
        return Order::where('business_id', $user->business_id)
            ->where('delivery_guy_id', $user->id)
            ->where('delivery_status', OrderDeliveryStatus::DELIVERED)
            ->whereNull('delivery_cash_settlement_id');
    }

    /** @return list<string> */
    private function actionsFor(Order $order): array
    {
        return self::ACTIONS[$order->delivery_status?->value ?? ''] ?? [];
    }

    private function findParcel(User $user, string $scanned): Order
    {
        $order = Order::where('business_id', $user->business_id)
            ->where('delivery_guy_id', $user->id)
            ->where('courier_tracking_number', TrackingNumberExtractor::extract($scanned))
            ->first();

        if (! $order) {
            throw ValidationException::withMessages(['qr_value' => __('No parcel of yours matches this code.')]);
        }

        return $order;
    }

    private function ownParcel(User $user, int $orderId): Order
    {
        return Order::where('business_id', $user->business_id)
            ->where('delivery_guy_id', $user->id)
            ->findOrFail($orderId);
    }

    /** Keeps the reason / date on the history row just written. */
    private function annotate(Order $order, string $note): void
    {
        if ($note === '') {
            return;
        }

        OrderStatusEvent::where('order_id', $order->id)->latest('id')->first()?->forceFill(['note' => $note])->save();
    }

    /** @return array<string, mixed> */
    private function payload(Order $order): array
    {
        $order->loadMissing(['items.product', 'items.variant', 'store:id,name'])
            ->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'courier_tracking_number' => $order->courier_tracking_number,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_address' => $order->customer_address,
            'customer_city' => $order->customer_city,
            'total_amount' => $order->total_amount,
            'delivery_status' => $order->delivery_status?->value,
            'parcel_note' => $order->parcel_note,
            'parcel_open' => $order->parcel_open,
            'parcel_fragile' => $order->parcel_fragile,
            'parcel_replace' => $order->parcel_replace,
            'store' => $order->store?->name,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name_snapshot,
                'sku' => $item->sku_snapshot,
                'quantity' => $item->quantity,
                'thumbnail' => optional($item->variant)->image ?? $item->product?->thumbnail,
            ])->values(),
        ];
    }
}
