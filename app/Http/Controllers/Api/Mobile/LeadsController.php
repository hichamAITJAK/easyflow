<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Enums\DeliveryAccountStatus;
use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use App\Http\Controllers\Concerns\FiltersOrdersByBucket;
use App\Http\Controllers\Concerns\ScopesAgentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\UpdateLeadRequest;
use App\Http\Requests\CreateShipmentRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The mobile Confirmation agent navigator's Leads screen backend
 * (PRD UC-7/8/9). Every query is scoped to the caller's own
 * assigned_agent_id via ScopesAgentAccess::applyOrderAssignmentScope() —
 * unlike the fulfilment queue, a confirmation agent's queue is personal,
 * not business-wide (see FulfillmentController's docblock for the
 * contrast). Filter buckets (new/follow_up/confirmed/shipped) come from
 * FiltersOrdersByBucket, shared with the web call-center queue
 * (OrderController) so both clients group statuses identically.
 */
class LeadsController extends Controller
{
    use FiltersOrdersByBucket;
    use ScopesAgentAccess;

    public function __construct(private readonly OrderService $orders) {}

    /**
     * List the caller's assigned leads, optionally filtered to one of the
     * Leads screen's filter buckets. "follow_up" groups every
     * post-contact-attempt status into one bucket (see FOLLOW_UP_STATUSES
     * on the mobile client) since agents think of these as one queue, not
     * six separate tabs.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $this->applyOrderAssignmentScope(Order::query(), $user)
            ->with(['items.product', 'items.variant'])
            ->where('business_id', $user->business_id)
            ->when($request->string('filter')->toString(), fn ($query, $filter) => $this->applyOrderBucket($query, $filter))
            ->orderByDesc('ordered_at');

        $orders = $query->get()->each(fn (Order $order) => $order->makeVisible(['customer_name', 'customer_phone', 'customer_address']));

        return response()->json(['leads' => $orders->map(fn (Order $order) => $this->leadPayload($order))]);
    }

    /**
     * Counts for each Leads screen filter chip badge, computed in one pass
     * so the tab bar never issues five separate count queries.
     */
    public function counts(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $this->applyOrderAssignmentScope(Order::query(), $user)
            ->where('business_id', $user->business_id);

        return response()->json($this->orderBucketCounts($query));
    }

    /**
     * A single lead's full detail, for the order-details screen.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        abort_unless($order->business_id === $user->business_id, 403);
        abort_if($this->isScopedAgent($user) && $order->assigned_agent_id !== $user->id, 403);

        $order->load(['items.product', 'items.variant'])->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        return response()->json(['lead' => $this->leadPayload($order)]);
    }

    /**
     * Change a lead's confirmation status (PRD UC-7/8/9). Reuses the same
     * UpdateOrderStatusRequest and OrderService::updateStatus() the web
     * orders page uses, so cancellation's structured-reason-code
     * requirement is enforced identically on both clients.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $user = $request->user();

        abort_unless($order->business_id === $user->business_id, 403);
        abort_if($this->isScopedAgent($user) && $order->assigned_agent_id !== $user->id, 403);

        $data = $request->validated();
        $newStatus = OrderConfirmationStatus::from($data['confirmation_status']);
        $cancellationReasonCode = isset($data['cancellation_reason_code'])
            ? OrderCancelReason::from($data['cancellation_reason_code'])
            : null;

        try {
            $order = $this->orders->updateStatus($order, $user, $newStatus, $cancellationReasonCode, $data['cancellation_note'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $order->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        return response()->json(['lead' => $this->leadPayload($order)]);
    }

    /**
     * Update a lead's customer details, note, and line items (PRD UC-7:
     * the confirmation call is exactly when a wrong address, phone, or
     * quantity surfaces).
     *
     * Delegates to OrderService::updateManualOrder(), the same method the
     * web orders page calls, so the phone-number normalisation, item
     * replacement, and note-clearing semantics are identical on both
     * clients rather than reimplemented here.
     */
    public function update(UpdateLeadRequest $request, Order $order): JsonResponse
    {
        $data = $request->validated();

        $this->orders->updateManualOrder($order, [
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'],
            'customer_address' => $data['customer_address'],
            'customer_city' => $data['customer_city'] ?? null,
            'total_amount' => (float) $data['total_amount'],
            'notes' => $data['notes'] ?? null,
            'items' => array_map(fn (array $item) => [
                'product_id' => $item['product_id'] ?? null,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'product_name' => $item['product_name'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
            ], $data['items'] ?? []),
        ]);

        $order->refresh()
            ->load(['items.product', 'items.variant'])
            ->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        return response()->json(['lead' => $this->leadPayload($order)]);
    }

    /**
     * The business's products and their variants, for the edit screen's
     * item picker. Only active products: an agent adding a discontinued
     * line to a live order would only create a fulfilment problem.
     */
    public function products(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('business_id', $request->user()->business_id)
            ->where('is_active', true)
            ->with(['variants' => fn ($query) => $query->where('is_available', true)->with('optionValues')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) ($product->price ?? 0),
                'thumbnail' => $product->thumbnail,
                'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    // Variants have no name column — they're identified by
                    // their option values ("Red · L"), with the SKU as a
                    // fallback for variants that carry none.
                    'name' => $variant->optionValues->pluck('value')->implode(' · ')
                        ?: ($variant->sku ?? 'Variant'),
                    'price' => $variant->price === null ? null : (float) $variant->price,
                    'image' => $variant->image,
                ]),
            ]),
        ]);
    }

    /**
     * The business's delivery courier accounts, for the ship-to-courier
     * picker. Only active accounts — an inactive one can't take a parcel,
     * so offering it would only produce a courier-side failure.
     */
    public function deliveryAccounts(Request $request): JsonResponse
    {
        $accounts = DeliveryAccount::where('business_id', $request->user()->business_id)
            ->where('status', DeliveryAccountStatus::ACTIVE)
            ->with('courier:id,name,slug')
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get(['id', 'label', 'courier_id', 'is_default']);

        return response()->json([
            'delivery_accounts' => $accounts->map(fn (DeliveryAccount $account) => [
                'id' => $account->id,
                'label' => $account->label,
                'courier' => $account->courier?->name,
                'is_default' => (bool) $account->is_default,
            ])->values(),
        ]);
    }

    /**
     * The cities one delivery account's courier delivers to. Scoped per
     * account rather than listed globally because every courier maintains
     * its own city list with its own ids — a city id only means anything
     * to the courier that issued it.
     */
    public function deliveryAccountCities(Request $request, DeliveryAccount $deliveryAccount): JsonResponse
    {
        abort_unless($deliveryAccount->business_id === $request->user()->business_id, 403);

        return response()->json([
            'cities' => $this->orders->citiesForDeliveryAccount($deliveryAccount),
        ]);
    }

    /**
     * Ship a confirmed lead to a delivery courier (PRD UC-12) — the
     * agent-initiated path, for when auto-creation didn't cover the order.
     *
     * Reuses the same CreateShipmentRequest and
     * OrderService::createShipment() the web orders page uses, so the
     * test-order backstop, the confirmed-only guard, and the parcel-payload
     * shape stay identical across both clients rather than drifting.
     *
     * A courier API failure is reported as 502, not 422: nothing the agent
     * typed is wrong, so the client should offer a retry rather than
     * highlighting a field. createShipment() has already dispatched
     * OrderDeliveryCreationFailed by then, so the order is flagged for
     * manual follow-up either way and never silently stuck.
     */
    public function createShipment(CreateShipmentRequest $request, Order $order): JsonResponse
    {
        $user = $request->user();

        abort_unless($order->business_id === $user->business_id, 403);
        abort_if($this->isScopedAgent($user) && $order->assigned_agent_id !== $user->id, 403);

        $validated = $request->validated();

        try {
            $order = $this->orders->createShipment($order, $user, [
                'delivery_account_id' => (int) $validated['delivery_account_id'],
                'city_id' => (int) $validated['city_id'],
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_address' => $validated['customer_address'],
                'total_amount' => (float) $validated['total_amount'],
                'parcel_note' => $validated['parcel_note'] ?? null,
                'parcel_nature' => $validated['parcel_nature'] ?? null,
                'parcel_open' => $validated['parcel_open'] ?? null,
                'parcel_fragile' => $validated['parcel_fragile'] ?? null,
                'parcel_replace' => $validated['parcel_replace'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        } catch (RequestException|ConnectionException) {
            abort(502, __('The courier rejected the shipment. Please try again.'));
        }

        $order->load(['items.product', 'items.variant'])->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        return response()->json(['lead' => $this->leadPayload($order)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function leadPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            // The ship-to-courier form pre-fills from these, so the agent
            // edits what the call actually established rather than retyping
            // an address from scratch.
            'customer_address' => $order->customer_address,
            'customer_city' => $order->customer_city,
            'products' => $order->items->map(fn ($item) => [
                'name' => $item->product_name_snapshot,
                'quantity' => $item->quantity,
                'price' => (float) $item->unit_price,
                // Prefer the specific variant's own image (e.g. the exact
                // color/size ordered); fall back to the product's
                // thumbnail. Null when the line item has no live
                // product/variant link (e.g. a manual order snapshot).
                'thumbnail' => optional($item->variant)->image ?? $item->product?->thumbnail,
            ]),
            'total_price' => (float) $order->total_amount,
            'confirmation_status' => $order->confirmation_status->value,
            'delivery_status' => $order->delivery_status?->value,
            'tracking_number' => $order->courier_tracking_number,
            'notes' => $order->notes,
            'is_duplicate_flagged' => $order->is_duplicate_flagged,
            'is_blacklist_flagged' => $order->is_blacklist_flagged,
            'created_at' => $order->ordered_at ?? $order->created_at,
        ];
    }
}
