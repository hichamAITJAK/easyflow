<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Enums\OrderDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Services\Operations\Orders\OrderService;
use App\Support\TrackingNumberExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Log;

/**
 * The mobile Fulfillment navigator's backend (UC-17).
 *
 * Deliberately does NOT use ScopesAgentAccess::applyOrderAssignmentScope()
 * — that method's own docblock excludes fulfilment agents from it
 * entirely, since fulfilment is business-wide, not scoped to an
 * individual agent's assignments (unlike the confirmation agent's order
 * queue).
 *
 * Design principle (product decision): the agent never chooses which scan
 * action to perform, and never browses a list looking for a parcel. The
 * whole navigator is Home (two counters) → camera → preview → one button,
 * plus a read-only activity log. A scanned parcel's CURRENT
 * delivery_status is the only thing that decides what happens next — this
 * removes a decision point (which mode am I in?) that is easy to get
 * wrong under fast, repetitive warehouse work. `resolveAction()` is the
 * single place that maps a delivery_status to the one legal next action,
 * and both `scan` (read-only preview) and `confirm` (the actual commit)
 * go through it, so the two can never disagree about what's allowed.
 *
 * Every scanned parcel previews — the agent always gets to see what they
 * scanned and why nothing can be done with it. Only two statuses carry an
 * action: awaiting_pickup (→ ready_for_pickup, outbound staging) and
 * returned_in_transit (→ return_received, inbound receipt). For anything
 * else `action` is null and the client shows the card with no button.
 */
class FulfillmentController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * Two counters for the Home screen: parcels ready to prepare
     * (awaiting_pickup, not yet staged) and returns pending physical
     * receipt (returned_in_transit, courier's claim only).
     */
    public function summary(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        return response()->json([
            'ready_to_prepare' => Order::where('business_id', $businessId)
                ->where('delivery_status', OrderDeliveryStatus::AWAITING_PICKUP)
                ->count(),
            'returns_pending' => Order::where('business_id', $businessId)
                ->where('delivery_status', OrderDeliveryStatus::RETURNED_IN_TRANSIT)
                ->count(),
        ]);
    }

    /**
     * Read-only preview for a scanned QR/barcode value: decodes it to a
     * tracking number, resolves the order, and reports the single action
     * the scan screen may offer — WITHOUT mutating anything.
     *
     * Takes the raw scanned payload rather than a tracking number because
     * couriers encode their labels differently (bare code, tracking URL,
     * URL with a query parameter); TrackingNumberExtractor owns that
     * decoding so the client never has to parse a label.
     *
     * Any order in the business previews. `action` is null when the
     * parcel's current status has nothing a scan can do — the client
     * shows the card without a button instead of an error.
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_value' => ['required', 'string'],
        ]);
        Log::info($data['qr_value']);
        $order = $this->findOrder($request, $data['qr_value']);

        return response()->json([
            'order' => $this->orderPayload($order),
            'action' => $this->resolveAction($order->delivery_status)?->value,
        ]);
    }

    /**
     * Commit the scan: re-derives the action from the order's CURRENT
     * delivery_status server-side (never trusts a client-supplied
     * action — see class docblock) and applies it via the single
     * service method allowed to change delivery_status (PRD section
     * 7.3).
     *
     * Takes an order_id rather than re-sending the scanned value: the
     * agent is confirming the specific parcel they just previewed, and
     * re-decoding the label here would let a second parcel with a
     * colliding candidate code slip in between preview and commit.
     * Rejects if the status moved on since the preview.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
        ]);

        $order = Order::where('business_id', $request->user()->business_id)
            ->findOrFail((int) $data['order_id']);

        $action = $this->resolveAction($order->delivery_status);

        if ($action === null) {
            throw ValidationException::withMessages([
                'order_id' => __("This parcel isn't expected for scanning right now."),
            ]);
        }

        $order = $this->orders->updateDeliveryStatus($order, $action, $request->user());

        return response()->json(['order' => $this->orderPayload($order)]);
    }

    /**
     * Today's scan log for the Activity screen: every delivery-status
     * transition this agent made via a scan, newest first. Read from the
     * append-only order_status_events audit table (PRD section 7.3's
     * single writer), not re-derived from orders, so it stays accurate
     * even after an order's status has since moved on.
     *
     * Undone scans are hidden. The audit table is append-only, so an undo
     * doesn't delete the scan's row — it appends a reversal row alongside
     * it (see undo()). Both are filtered out here: the reversal because
     * its to_status isn't a scan action, and the scan itself because a
     * later reversal for the same order exists. What's left is what the
     * agent actually did and did not take back, which is what the Activity
     * screen is for. The audit trail keeps both rows regardless.
     */
    public function activity(Request $request): JsonResponse
    {
        $scanActions = [OrderDeliveryStatus::READY_FOR_PICKUP->value, OrderDeliveryStatus::RETURN_RECEIVED->value];

        $events = OrderStatusEvent::query()
            ->with(['order:id,courier_tracking_number,customer_name,customer_address'])
            ->where('business_id', $request->user()->business_id)
            ->where('changed_by_user_id', $request->user()->id)
            ->whereIn('to_status', $scanActions)
            ->whereDate('created_at', now()->toDateString())
            // A reversal recorded after this scan, undoing exactly the
            // status this scan set — matched on from_status so an
            // unrelated later transition on the same order (a courier
            // moving it to in_transit, say) doesn't hide a real scan.
            ->whereNotExists(function ($query) use ($request) {
                $query->selectRaw('1')
                    ->from('order_status_events as reversal')
                    ->whereColumn('reversal.order_id', 'order_status_events.order_id')
                    ->whereColumn('reversal.from_status', 'order_status_events.to_status')
                    ->whereColumn('reversal.id', '>', 'order_status_events.id')
                    ->where('reversal.business_id', $request->user()->business_id)
                    ->whereIn('reversal.to_status', [
                        OrderDeliveryStatus::AWAITING_PICKUP->value,
                        OrderDeliveryStatus::RETURNED_IN_TRANSIT->value,
                    ]);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->each(fn (OrderStatusEvent $event) => $event->order?->makeVisible(['customer_name', 'customer_address']));

        $undoWindowStart = now()->subMinutes(5);

        return response()->json([
            'events' => $events->map(fn (OrderStatusEvent $event) => [
                'id' => $event->id,
                'order_id' => $event->order_id,
                'tracking_number' => $event->order?->courier_tracking_number,
                'customer_name' => $event->order?->customer_name,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'created_at' => $event->created_at,
                'can_undo' => $event->created_at !== null && $event->created_at->greaterThan($undoWindowStart),
            ]),
        ]);
    }

    /**
     * Reverse the single most recent scan action on an order, only within
     * a short window (5 minutes) — covers a mis-scan or double-tap without
     * letting an agent rewrite history from hours ago. Goes through the
     * same updateDeliveryStatus service method as any other transition,
     * so it's logged and event-fired like a normal status change, not a
     * silent rollback.
     */
    public function undo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer'],
        ]);

        $event = OrderStatusEvent::query()
            ->where('id', $data['event_id'])
            ->where('business_id', $request->user()->business_id)
            ->where('changed_by_user_id', $request->user()->id)
            ->whereIn('to_status', [OrderDeliveryStatus::READY_FOR_PICKUP->value, OrderDeliveryStatus::RETURN_RECEIVED->value])
            ->first();

        if (! $event || $event->created_at === null || $event->created_at->lessThan(now()->subMinutes(5))) {
            throw ValidationException::withMessages([
                'event_id' => __('This scan can no longer be undone.'),
            ]);
        }

        $order = Order::where('business_id', $request->user()->business_id)->findOrFail($event->order_id);

        if ($order->delivery_status?->value !== $event->to_status) {
            throw ValidationException::withMessages([
                'event_id' => __("This parcel's status has changed since this scan — it can no longer be undone."),
            ]);
        }

        $revertTo = $event->to_status === OrderDeliveryStatus::READY_FOR_PICKUP->value
            ? OrderDeliveryStatus::AWAITING_PICKUP
            : OrderDeliveryStatus::RETURNED_IN_TRANSIT;

        $order = $this->orders->updateDeliveryStatus($order, $revertTo, $request->user());

        return response()->json(['order' => $this->orderPayload($order)]);
    }

    /**
     * The single mapping from a delivery_status to the one action a scan
     * may perform. Returns null when nothing is legal to do — the caller
     * turns that into either the read-only "not expected" warning
     * (lookup) or a rejection (scan).
     */
    private function resolveAction(?OrderDeliveryStatus $status): ?OrderDeliveryStatus
    {
        return match ($status) {
            OrderDeliveryStatus::AWAITING_PICKUP => OrderDeliveryStatus::READY_FOR_PICKUP,
            OrderDeliveryStatus::RETURNED_IN_TRANSIT => OrderDeliveryStatus::RETURN_RECEIVED,
            default => null,
        };
    }

    /**
     * Decode a raw scanned label to the order behind it, tenant-scoped.
     *
     * The scanned value is usually the tracking number itself;
     * TrackingNumberExtractor handles the couriers that wrap it in
     * something else (see that class), so the lookup here stays a single
     * indexed query no matter what shape the label used.
     */
    private function findOrder(Request $request, string $scannedValue): Order
    {
        $order = Order::where('business_id', $request->user()->business_id)
            ->where('courier_tracking_number', TrackingNumberExtractor::extract($scannedValue))
            ->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'qr_value' => __('No order found for this scanned code.'),
            ]);
        }

        return $order->loadMissing([
            'store:id,name',
            'deliveryAccount.courier:id,name,slug',
            'items.product',
            'items.variant',
        ]);
    }

    /**
     * The preview card's payload. Carries the parcel handling flags and
     * store/courier context that used to arrive via the queue list —
     * there's no list screen any more, so the scan result is the only
     * place the agent sees them.
     *
     * `items` is what the agent physically picks and packs, read from the
     * order_items line items (name/sku/quantity snapshots taken at order
     * time). Distinct from `parcel_products`, which is the courier-facing
     * declaration sent with the parcel — the two can legitimately differ,
     * so both are exposed rather than one standing in for the other.
     *
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order): array
    {
        $order->makeVisible(['customer_name', 'customer_address']);
        $order->loadMissing(['items.product', 'items.variant']);

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'store' => $order->store?->name,
            'courier' => $order->deliveryAccount?->courier?->name,
            'customer_name' => $order->customer_name,
            'customer_address' => $order->customer_address,
            'customer_city' => $order->customer_city,
            'courier_tracking_number' => $order->courier_tracking_number,
            'delivery_status' => $order->delivery_status?->value,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name_snapshot,
                'sku' => $item->sku_snapshot,
                'quantity' => $item->quantity,
                // Prefer the specific variant's own image (the exact
                // colour/size ordered) so the agent picks the right one off
                // the shelf; fall back to the product thumbnail. Null when
                // the line item has no live product/variant link.
                'thumbnail' => optional($item->variant)->image ?? $item->product?->thumbnail,
            ])->values(),
            'parcel_products' => $order->parcel_products,
            'parcel_note' => $order->parcel_note,
            'parcel_nature' => $order->parcel_nature,
            'parcel_open' => $order->parcel_open,
            'parcel_fragile' => $order->parcel_fragile,
            'parcel_replace' => $order->parcel_replace,
            'ready_for_pickup_at' => $order->ready_for_pickup_at,
            'return_received_at' => $order->return_received_at,
        ];
    }
}
