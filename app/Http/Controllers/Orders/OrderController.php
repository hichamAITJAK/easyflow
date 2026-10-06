<?php

namespace App\Http\Controllers\Orders;

use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\BuildsTableQuery;
use App\Http\Controllers\Concerns\FiltersOrdersByBucket;
use App\Http\Controllers\Concerns\LoadsEcomIntegrationData;
use App\Http\Controllers\Concerns\ScopesAgentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignOrderRequest;
use App\Http\Requests\BlacklistOrderRequest;
use App\Http\Requests\CreateShipmentRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Operations\Customers\CustomerService;
use App\Services\Operations\Orders\OrderService;
use App\Services\PostHogService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class OrderController extends Controller
{
    use BuildsTableQuery;
    use FiltersOrdersByBucket;
    use LoadsEcomIntegrationData;
    use ScopesAgentAccess;

    /**
     * Columns the orders table may be sorted by, keyed to their allowed
     * query-string `sort` value.
     */
    private const SORTABLE = ['reference', 'total_amount', 'delivery_cost', 'confirmation_status', 'delivery_status', 'ordered_at', 'source_platform'];

    /**
     * Columns searched by the `search` query param.
     */
    private const SEARCHABLE = ['reference', 'courier_tracking_number'];

    public function __construct(
        private readonly OrderService $orders,
        private readonly PostHogService $posthog,
    ) {}

    /**
     * Display the orders list with filters and status metrics.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $businessId = $user->business_id;
        $scopedStoreIds = $this->scopedStoreIds($user);

        $query = $this->applyOrderAssignmentScope(Order::query(), $user)
            ->with(['store.platform', 'assignedAgent', 'items.product', 'items.variant'])
            ->where('business_id', $businessId)
            ->when($request->string('confirmation_status')->toString(), fn ($query, $status) => $query->where('confirmation_status', $status))
            ->when($request->string('delivery_status')->toString(), fn ($query, $status) => $query->where('delivery_status', $status))
            ->when($request->string('bucket')->toString(), fn ($query, $bucket) => $this->applyOrderBucket($query, $bucket))
            ->when($this->resolveIdList($request, 'store_ids', 'store_id'), fn ($query, array $storeIds) => $query->whereIn('store_id', $storeIds))
            ->when(
                $request->input('assigned_agent_id') === '__unassigned__',
                fn ($query) => $query->whereNull('assigned_agent_id'),
                fn ($query) => $query->when($request->integer('assigned_agent_id'), fn ($query, $agentId) => $query->where('assigned_agent_id', $agentId))
            )
            ->when($request->date('date_from'), fn ($query, $date) => $query->whereDate('ordered_at', '>=', $date))
            ->when($request->date('date_to'), fn ($query, $date) => $query->whereDate('ordered_at', '<=', $date));

        $query = $this->applySearch($query, $request, self::SEARCHABLE, hashColumn: 'customer_phone_hash');
        $query = $this->applySort($query, $request, self::SORTABLE, default: 'ordered_at');

        $orders = $query->paginate($this->resolvePerPage($request))->withQueryString();

        $orders->getCollection()->each->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        $confirmationMetrics = $this->applyOrderAssignmentScope(Order::query(), $user)
            ->where('business_id', $businessId)
            ->selectRaw('confirmation_status, count(*) as aggregate')
            ->groupBy('confirmation_status')
            ->pluck('aggregate', 'confirmation_status');

        $deliveredCount = $this->applyOrderAssignmentScope(Order::query(), $user)
            ->where('business_id', $businessId)
            ->where('delivery_status', OrderDeliveryStatus::DELIVERED->value)
            ->count();

        $bucketCounts = $this->orderBucketCounts(
            $this->applyOrderAssignmentScope(Order::query(), $user)->where('business_id', $businessId)
        );

        // A confirmation agent's queue is a call-center workflow (PRD
        // section: "the confirmation queue is the product's core daily
        // tool") — one order at a time, full detail always visible, never
        // the admin's wide scan-everything datatable. Fulfilment agents
        // never reach here at all (applyOrderAssignmentScope excludes them
        // entirely, see its docblock), so this only ever means confirmation.
        $component = $user->role === UserRole::CONFIRMATION_AGENT
            ? 'orders/queue'
            : 'orders/index';

        return Inertia::render($component, [
            'orders' => $orders,
            'metrics' => [
                'new' => (int) ($confirmationMetrics[OrderConfirmationStatus::NEW->value] ?? 0),
                'confirmed' => (int) ($confirmationMetrics[OrderConfirmationStatus::CONFIRMED->value] ?? 0),
                // Reads off the same grouped count as the others — it's a
                // confirmation status, not a delivery one, so no extra query.
                'submitted_to_courier' => (int) ($confirmationMetrics[OrderConfirmationStatus::SUBMITTED_TO_COURIER->value] ?? 0),
                'delivered' => $deliveredCount,
                'cancelled' => (int) ($confirmationMetrics[OrderConfirmationStatus::CANCELLED->value] ?? 0),
            ],
            // store_ids is normalised rather than echoed raw so a legacy
            // ?store_id=3 link comes back as the plural param the picker
            // reads — otherwise the list would be filtered while the
            // control showed "All stores".
            'filters' => [
                ...$request->only(['search', 'sort', 'direction', 'per_page', 'confirmation_status', 'delivery_status', 'assigned_agent_id', 'date_from', 'date_to', 'bucket']),
                'store_ids' => $this->idListParam($request, 'store_ids', 'store_id'),
            ],
            'bucketCounts' => $bucketCounts,
            // connection_status rides along so the sync picker can say which
            // stores it can actually pull from — syncing skips anything not
            // connected, and a picker that offered them silently would
            // report "0 orders synced" with no reason given.
            'stores' => Store::where('business_id', $businessId)
                ->when($scopedStoreIds !== null, fn ($query) => $query->whereIn('id', $scopedStoreIds))
                ->orderBy('name')
                ->get(['id', 'name', 'connection_status']),
            'deliveryAccounts' => DeliveryAccount::where('business_id', $businessId)
                ->with('courier:id,name,slug')
                ->get(['id', 'courier_id', 'label']),
            'agents' => User::where('business_id', $businessId)
                ->where('role', UserRole::CONFIRMATION_AGENT)
                ->orderBy('name')
                ->get(['id', 'name', 'avatar']),
        ]);
    }

    /**
     * Full detail for a single order — items, event history, cancellation/
     * return reasons, notes — everything the trimmed list columns and the
     * agent queue's compact cards leave out. Powers both the admin's large
     * view dialog and the agent call-center detail pane, so the two never
     * drift into showing different information for the same order.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        abort_unless($order->business_id === $user->business_id, 403);
        abort_if($this->isScopedAgent($user) && $order->assigned_agent_id !== $user->id, 403);

        $order->load([
            'store.platform',
            'assignedAgent',
            'items.product',
            'items.variant',
            'deliveryAccount.courier',
            'statusEvents' => fn ($query) => $query->with('changedByUser:id,name')->latest('created_at'),
        ])->makeVisible(['customer_name', 'customer_phone', 'customer_address']);

        return response()->json(['order' => $order]);
    }

    /**
     * Manually create an order.
     */
    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        $this->orders->createManualOrder($user->business_id, [
            // A confirmation agent who keys in an order is the one who
            // took it (phone, WhatsApp…), so it is theirs from the start
            // rather than going through load-based auto-assignment.
            'assigned_agent_id' => $user->role === UserRole::CONFIRMATION_AGENT ? $user->id : null,
            'source_platform' => $validated['source_platform'] ?? null,
            'customer_name' => $validated['customer_name'],
            'customer_phone' => $validated['customer_phone'],
            'customer_address' => $validated['customer_address'],
            'customer_city' => $validated['customer_city'] ?? null,
            'total_amount' => (float) $validated['total_amount'],
            'notes' => $validated['notes'] ?? null,
            'items' => array_map(fn (array $item) => [
                'product_id' => $item['product_id'] ?? null,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'product_name' => $item['product_name'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
            ], $validated['items'] ?? []),
        ], $user);

        // PostHog: Track manual order creation
        $this->posthog->capture((string) $user->id, 'order_created', [
            'source' => 'manual',
            'total_amount' => (float) $validated['total_amount'],
            'item_count' => count($validated['items'] ?? []),
            'business_id' => $user->business_id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order created.')]);

        return back(fallback: route('orders.index'));
    }

    /**
     * Update an order's customer details and line items. Locked once the
     * order has shipped (see UpdateOrderRequest::authorize) — the courier
     * already has it by then.
     */
    public function update(UpdateOrderRequest $request, Order $order): RedirectResponse
    {
        $validated = $request->validated();

        $this->orders->updateManualOrder($order, [
            'source_platform' => $validated['source_platform'] ?? null,
            'customer_name' => $validated['customer_name'],
            'customer_phone' => $validated['customer_phone'],
            'customer_address' => $validated['customer_address'],
            'customer_city' => $validated['customer_city'] ?? null,
            'total_amount' => (float) $validated['total_amount'],
            'notes' => $validated['notes'] ?? null,
            'items' => array_map(fn (array $item) => [
                'product_id' => $item['product_id'] ?? null,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'product_name' => $item['product_name'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
            ], $validated['items'] ?? []),
        ], trackUpsell: $request->user()->role === UserRole::CONFIRMATION_AGENT);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order updated.')]);

        return back(fallback: route('orders.index'));
    }

    /**
     * Update an order's confirmation status, recording a status event.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): RedirectResponse
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
            $this->orders->updateStatus($order, $user, $newStatus, $cancellationReasonCode, $data['cancellation_note'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        // PostHog: Track order status update
        $this->posthog->capture((string) $user->id, 'order_status_updated', [
            'new_status' => $newStatus->value,
            'cancellation_reason' => $cancellationReasonCode?->value,
            'business_id' => $user->business_id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order status updated.')]);

        return back(fallback: route('orders.index'));
    }

    /**
     * Create a shipment for a confirmed order at the chosen delivery
     * courier account (UC-12): persists the customer-info edits and courier
     * choice, registers the parcel with the courier's API, and transitions
     * the order to submitted_to_courier.
     */
    public function createShipment(CreateShipmentRequest $request, Order $order): RedirectResponse
    {
        $user = $request->user();

        abort_unless($order->business_id === $user->business_id, 403);
        abort_if($this->isScopedAgent($user) && $order->assigned_agent_id !== $user->id, 403);

        $validated = $request->validated();

        try {
            $this->orders->createShipment($order, $user, [
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
        } catch (RequestException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The courier rejected the shipment. Please try again or contact support.')]);

            return back(fallback: route('orders.index'));
        }

        // PostHog: Track shipment creation
        $this->posthog->capture((string) $user->id, 'order_shipment_created', [
            'delivery_account_id' => (int) $validated['delivery_account_id'],
            'total_amount' => (float) $validated['total_amount'],
            'business_id' => $user->business_id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shipment created.')]);

        return back(fallback: route('orders.index'));
    }

    /**
     * List the cities covered by a delivery account's courier, for the
     * create-shipment dialog's dependent city dropdown. Scoped to the
     * caller's business so a crafted account id can't be used to probe
     * another business's connected couriers.
     */
    public function citiesForDeliveryAccount(Request $request, DeliveryAccount $deliveryAccount): JsonResponse
    {
        abort_unless($deliveryAccount->business_id === $request->user()->business_id, 403);

        return response()->json(['cities' => $this->orders->citiesForDeliveryAccount($deliveryAccount)]);
    }

    /**
     * List the caller's business's products, for the manual order form's
     * line-item picker. Manual orders have no store of their own — the
     * picker searches across every store the business has connected,
     * optionally filtered by a `search` term to keep the result small.
     * Each product's variants (with their resolved option name/value pairs)
     * are included so the picker can offer a variant choice when one exists.
     */
    public function products(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $products = Product::where('business_id', $request->user()->business_id)
            ->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->with('variants.optionValues.option')
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'price', 'inventory_quantity'])
            ->map(fn (Product $product) => $this->mapProductForPicker($product));

        return response()->json(['products' => $products]);
    }

    /**
     * Map a product (with its variants/optionValues/option relations
     * eager-loaded) into the shape the manual order form's line-item picker
     * expects. Extracted from products() — and its variant/option-value
     * mapping further extracted into their own methods below — so each
     * nesting level has an explicit return type instead of relying on
     * PHPStan to infer the self-referential shape of nested map() closures.
     *
     * @return array{id: int, name: string, price: float|null, inventory_quantity: int|null, variants: array<int, array{id: int, sku: string|null, price: float|null, is_available: bool, inventory_quantity: int|null, options: array<int, array{name: string, value: string}>}>}
     */
    private function mapProductForPicker(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            // Only consulted for products with no variants; a variant's own
            // stock wins whenever one is chosen.
            'inventory_quantity' => $product->inventory_quantity,
            'variants' => $product->variants
                ->map(fn (ProductVariant $variant) => $this->mapVariantForPicker($variant))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{id: int, sku: string|null, price: float|null, is_available: bool, inventory_quantity: int|null, options: array<int, array{name: string, value: string}>}
     */
    private function mapVariantForPicker(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'price' => $variant->price,
            'is_available' => $variant->is_available,
            // Null means "not tracked" and stays null rather than collapsing
            // to 0 — an untracked variant must not read as out of stock.
            'inventory_quantity' => $variant->inventory_quantity,
            'options' => $variant->optionValues
                ->map(fn (ProductOptionValue $value) => [
                    'name' => $value->option->name,
                    'value' => $value->value,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Assign (or unassign) an order to an agent.
     */
    public function assign(AssignOrderRequest $request, Order $order): RedirectResponse
    {
        abort_unless($order->business_id === $request->user()->business_id, 403);

        $this->orders->assign($order, $request->validated()['assigned_agent_id'] ?? null, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order assigned.')]);

        return back(fallback: route('orders.index'));
    }

    /**
     * Blacklist the client behind an order (UC-10): records a
     * CustomerBlacklistEntry for their phone and flags every one of their
     * orders in this business, not just this one. Test orders never carry
     * a real client, so they can't be used to blacklist a phone number
     * (UC-25).
     */
    public function blacklist(BlacklistOrderRequest $request, Order $order, CustomerService $customers): RedirectResponse
    {
        abort_unless($order->business_id === $request->user()->business_id, 403);
        abort_if($order->is_test, 422, 'Test orders cannot be used to blacklist a phone number.');

        $validated = $request->validated();
        $customers->blacklistOrderCustomer($order, $validated['reason'] ?? null, $validated['notes'] ?? null, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customer blacklisted.')]);

        return back(fallback: route('orders.index'));
    }

    /**
     * Soft-delete an order.
     */
    public function destroy(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->business_id === $request->user()->business_id, 403);

        $this->orders->destroy($order);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order deleted.')]);

        return back(fallback: route('orders.index'));
    }

    /**
     * Soft-delete several orders at once, scoped to the caller's business
     * so a crafted id list can't touch another business's orders.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $count = $this->orders->bulkDestroy($request->user()->business_id, $data['ids']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count order deleted.|:count orders deleted.', $count, ['count' => $count]),
        ]);

        return back(fallback: route('orders.index'));
    }

    /**
     * Assign (or unassign) several orders at once.
     */
    public function bulkAssign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $count = $this->orders->bulkAssign($request->user()->business_id, $data['ids'], $data['assigned_agent_id'] ?? null, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count order assigned.|:count orders assigned.', $count, ['count' => $count]),
        ]);

        return back(fallback: route('orders.index'));
    }

    /**
     * Update confirmation status for several orders at once.
     */
    public function bulkStatus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            // Same manually-selectable restriction the single-order
            // endpoint applies — a bulk call must not be a way around it.
            'confirmation_status' => ['required', Rule::enum(OrderConfirmationStatus::class)->only(OrderConfirmationStatus::manuallySelectable())],
        ]);

        $newStatus = OrderConfirmationStatus::from($data['confirmation_status']);
        $count = $this->orders->bulkUpdateStatus($request->user()->business_id, $data['ids'], $newStatus, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count order status updated.|:count orders status updated.', $count, ['count' => $count]),
        ]);

        return back(fallback: route('orders.index'));
    }

    /**
     * Load orders live from an ecom platform and persist them, upserting
     * on (store_id, external_order_id) so re-syncing never duplicates rows.
     *
     * Order line items are matched against this business's already-synced
     * products, so a store whose products haven't been synced yet is
     * skipped with a warning rather than silently creating orders whose
     * items can't be linked to a product.
     */
    public function sync(Request $request): RedirectResponse
    {
        $request->validate([
            'platform' => ['required', 'string'],
            // Scopes the sync to one store. Absent means every connected
            // store, which stays the default.
            'store_id' => ['nullable', 'integer'],
        ]);

        $platformValue = $request->string('platform')->toString();
        $platformSlug = $platformValue === '*' ? '*' : $this->resolvePlatform($platformValue)->value;
        $storeId = $request->integer('store_id') ?: null;

        $user = $request->user();
        $result = $this->orders->syncFromPlatform($user->business_id, $platformSlug, $storeId);

        if ($result['stores_missing_products'] !== []) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Sync products first for: :stores', ['stores' => implode(', ', $result['stores_missing_products'])]),
            ]);

            return back(fallback: route('orders.index'));
        }

        // PostHog: Track orders sync
        $this->posthog->capture((string) $user->id, 'orders_synced', [
            'platform' => $platformSlug,
            'synced_count' => $result['synced'],
            'business_id' => $user->business_id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count order synced.|:count orders synced.', $result['synced'], ['count' => $result['synced']]),
        ]);

        return back(fallback: route('orders.index'));
    }
}
