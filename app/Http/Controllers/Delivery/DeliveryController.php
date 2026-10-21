<?php

namespace App\Http\Controllers\Delivery;

use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Http\Controllers\Concerns\BuildsTableQuery;
use App\Http\Controllers\Controller;
use App\Models\DeliveryCashSettlement;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\User;
use App\Services\Operations\Orders\OrderService;
use App\Services\Operations\Settlements\DeliveryCashSettlementService;
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
    use BuildsTableQuery;

    private const UNDO_MINUTES = 5;

    /** A parcel out with the guy, whatever happened on the last attempt. */
    private const OUT = ['out_for_delivery', 'postponed', 'delivery_attempt_failed'];

    /**
     * What the guy may mark, with the same labels the couriers use (the
     * warehouse and the admins already read Coliix's words in the parcel
     * history). Each maps to our status; the label goes on the history
     * row. `from` is where the parcel must be for the action to show.
     *
     * @var array<string, array{status: string, label: string, from: list<string>, needs_date?: bool, return_reason?: OrderReturnReason}>
     */
    private const ACTIONS = [
        'ramasse' => ['status' => 'out_for_delivery', 'label' => 'Ramassé', 'from' => ['ready_for_pickup']],
        'mise_en_distribution' => ['status' => 'out_for_delivery', 'label' => 'Mise en distribution', 'from' => ['postponed', 'delivery_attempt_failed']],
        'livre' => ['status' => 'delivered', 'label' => 'Livré', 'from' => self::OUT],
        'pas_de_reponse' => ['status' => 'delivery_attempt_failed', 'label' => 'Pas de réponse', 'from' => self::OUT],
        'deuxieme_appel' => ['status' => 'delivery_attempt_failed', 'label' => 'Deuxième Appel Pas Réponse', 'from' => self::OUT],
        'troisieme_appel' => ['status' => 'delivery_attempt_failed', 'label' => 'Troisième Appel Pas Réponse', 'from' => self::OUT],
        'boite_vocal' => ['status' => 'delivery_attempt_failed', 'label' => 'Boite Vocal', 'from' => self::OUT],
        'injoignable' => ['status' => 'delivery_attempt_failed', 'label' => 'Injoignable', 'from' => self::OUT],
        'numero_errone' => ['status' => 'delivery_attempt_failed', 'label' => 'Numero Erroné', 'from' => self::OUT],
        'hors_zone' => ['status' => 'delivery_attempt_failed', 'label' => 'Hors-zone', 'from' => self::OUT],
        'client_interesse' => ['status' => 'delivery_attempt_failed', 'label' => 'Client intéressé', 'from' => self::OUT],
        'reporte' => ['status' => 'postponed', 'label' => 'Reporté', 'from' => self::OUT, 'needs_date' => true],
        'refuse' => ['status' => 'refused', 'label' => 'Refusé', 'from' => self::OUT],
        'retourne' => ['status' => 'returned_in_transit', 'label' => 'Retourné', 'from' => self::OUT, 'return_reason' => OrderReturnReason::OTHER],
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
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'postponed_until' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->ownParcel($user, (int) $data['order_id']);
        $action = self::ACTIONS[$data['action']];

        if (! in_array($order->delivery_status?->value, $action['from'], true)) {
            throw ValidationException::withMessages([
                'action' => __("This parcel can't be marked like that right now."),
            ]);
        }

        if (($action['needs_date'] ?? false) && empty($data['postponed_until'])) {
            throw ValidationException::withMessages(['postponed_until' => __('Pick the new delivery date.')]);
        }

        $status = OrderDeliveryStatus::from($action['status']);
        $note = implode(' · ', array_filter([
            $action['label'],
            ($action['needs_date'] ?? false) ? Carbon::parse($data['postponed_until'])->toDateString() : null,
            $data['note'] ?? null,
        ]));

        if ($order->delivery_status === $status) {
            // A second "Pas de réponse" changes nothing on the order, but
            // the attempt itself is worth a line in the history.
            OrderStatusEvent::create([
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'from_status' => $status->value,
                'to_status' => $status->value,
                'changed_by_user_id' => $user->id,
                'note' => $note,
            ]);
        } else {
            $order = $this->orders->updateDeliveryStatus($order, $status, $user, $action['return_reason'] ?? null);
            $this->annotate($order, $note);
        }

        return response()->json([
            'order' => $this->payload($order->fresh()),
            'actions' => $this->actionsFor($order->fresh()),
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
            ->whereIn('to_status', array_values(array_unique(array_column(self::ACTIONS, 'status'))))
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

        $settlements = app(DeliveryCashSettlementService::class);

        $parcels = $this->owedParcels($user)
            ->orderByDesc('updated_at')
            ->get(['id', 'reference', 'courier_tracking_number', 'customer_name', 'customer_city', 'total_amount', 'delivery_cost', 'updated_at'])
            ->each->makeVisible(['customer_name']);

        $carried = $settlements->carried($user);

        $last = DeliveryCashSettlement::where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return response()->json([
            'owed' => $settlements->owed($user),
            // Left open by the last hand-over; positive means still owed.
            'carried' => $carried,
            'parcels' => $parcels->map(fn (Order $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'tracking_number' => $order->courier_tracking_number,
                'customer_name' => $order->customer_name,
                'customer_city' => $order->customer_city,
                'amount' => number_format((float) $order->total_amount - (float) ($order->delivery_cost ?? 0), 2, '.', ''),
                'total' => $order->total_amount,
                'pay' => number_format((float) ($order->delivery_cost ?? 0), 2, '.', ''),
                'delivered_at' => $order->updated_at,
            ])->values(),
            'last_settlement' => $last === null ? null : [
                'amount' => $last->amount,
                'received_amount' => $last->received_amount,
                'balance_after' => $last->balance_after,
                'parcels_count' => $last->parcels_count,
                'created_at' => $last->created_at,
            ],
        ]);
    }

    /**
     * Every parcel ever handed to this delivery guy, newest first, for
     * his own list: where each one stands and what it is worth.
     */
    public function parcels(Request $request): Response
    {
        $user = $request->user();

        $query = Order::where('business_id', $user->business_id)
            ->where('delivery_guy_id', $user->id)
            ->when($request->string('delivery_status')->toString(), fn ($query, $status) => $query->where('delivery_status', $status))
            ->orderByDesc('updated_at');

        $query = $this->applySearch($query, $request, ['reference', 'courier_tracking_number'], hashColumn: 'customer_phone_hash');

        $parcels = $query->paginate($this->resolvePerPage($request), ['id', 'reference', 'courier_tracking_number', 'customer_name', 'customer_phone', 'customer_city', 'customer_address', 'total_amount', 'delivery_cost', 'delivery_status', 'delivery_cash_settlement_id', 'shipped_at', 'updated_at'])
            ->withQueryString();

        $parcels->getCollection()->each->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        return Inertia::render('delivery/parcels', [
            'filters' => $request->only(['search', 'delivery_status', 'per_page']),
            'parcels' => $parcels->through(fn (Order $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'tracking_number' => $order->courier_tracking_number,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_city' => $order->customer_city,
                'customer_address' => $order->customer_address,
                'amount' => $order->total_amount,
                'delivery_status' => $order->delivery_status?->value,
                'settled' => $order->delivery_cash_settlement_id !== null,
                'shipped_at' => $order->shipped_at,
                'updated_at' => $order->updated_at,
            ]),
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
            'cash_owed' => app(DeliveryCashSettlementService::class)->owed($user),
            'city' => $user->delivery_city,
        ];
    }

    /** @return Builder<Order> */
    private function owedParcels(User $user)
    {
        return app(DeliveryCashSettlementService::class)->owedParcels($user);
    }

    /**
     * The actions this parcel's status allows, in the order they are shown.
     *
     * @return list<array{key: string, label: string, status: string, needs_date: bool}>
     */
    private function actionsFor(Order $order): array
    {
        $current = $order->delivery_status?->value;

        return collect(self::ACTIONS)
            ->filter(fn (array $action) => in_array($current, $action['from'], true))
            ->map(fn (array $action, string $key) => [
                'key' => $key,
                'label' => $action['label'],
                'status' => $action['status'],
                'needs_date' => $action['needs_date'] ?? false,
            ])
            ->values()
            ->all();
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
