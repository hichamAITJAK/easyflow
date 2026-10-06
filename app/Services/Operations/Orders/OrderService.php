<?php

namespace App\Services\Operations\Orders;

use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Enums\StoreConnectionStatus;
use App\Events\Order\ConfirmationStatusChanged;
use App\Events\Order\DeliveryStatusChanged;
use App\Events\Order\OrderAssigned;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderConfirmed;
use App\Events\Order\OrderCreated;
use App\Events\Order\OrderDelivered;
use App\Events\Order\OrderDeliveryCreationFailed;
use App\Events\Order\OrderReturnedInTransit;
use App\Events\Order\OrderSubmittedToCourier;
use App\Events\Order\ParcelReadyForPickup;
use App\Events\Order\ParcelReturnReceived;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Operations\IntegrationManagerService;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Order business logic shared by every client (web, mobile). Methods here
 * take and return plain models/scalars/arrays only — never a Request,
 * never an HTTP response. Callers (controllers) are responsible for input
 * validation, authorization, and shaping the result for their transport.
 */
class OrderService
{
    public function __construct(
        private readonly OrderCodeGenerator $codeGenerator,
        private readonly OrderSyncService $orderSync,
        private readonly IntegrationManagerService $manager = new IntegrationManagerService,
        private readonly ParcelProductsBuilder $parcelProducts = new ParcelProductsBuilder,
    ) {}

    /**
     * Manually create an order for the given business, along with its line
     * items (if any). A manual order never has a store — it didn't come
     * from a synced ecom platform, so source_platform instead records the
     * channel it arrived through (whatsapp/phone_call/other, see
     * App\Enums\OrderSource), falling back to 'manual' when unspecified.
     *
     * total_amount is trusted as given — the caller (the manual order form)
     * is responsible for keeping it in sync with the items it submits, so
     * this stays a straight persist rather than a silent recompute that
     * could surprise an agent who deliberately overrode the total.
     *
     * assigned_agent_id, when given, hands the order to that agent before
     * OrderCreated fires, so the load-based auto-assign listener leaves it
     * alone (an agent typing in an order they took on the phone owns it).
     * $actor is who performed that assignment for the audit log.
     *
     * @param  array{assigned_agent_id?: int|null, source_platform?: string|null, customer_name: string, customer_phone: string, customer_address: string, customer_city?: string|null, total_amount: float, notes?: string|null, items?: array<int, array{product_id?: int|null, product_variant_id?: int|null, product_name: string, quantity: int, unit_price: float}>}  $data
     */
    public function createManualOrder(int $businessId, array $data, ?User $actor = null): Order
    {
        $customerPhone = PhoneNumber::format($data['customer_phone']) ?? $data['customer_phone'];

        $productIds = array_filter(array_column($data['items'] ?? [], 'product_id'));
        $isTest = $productIds !== []
            && Product::where('business_id', $businessId)->whereIn('id', $productIds)->where('is_test', true)->exists();

        $order = Order::create([
            'reference' => $this->codeGenerator->reference(),
            'business_id' => $businessId,
            'source_platform' => $data['source_platform'] ?? 'manual',
            'customer_name' => $data['customer_name'],
            'customer_phone' => $customerPhone,
            'customer_phone_hash' => hash('sha256', $customerPhone),
            'customer_address' => $data['customer_address'],
            'customer_city' => $data['customer_city'] ?? null,
            'total_amount' => $data['total_amount'],
            'notes' => $data['notes'] ?? null,
            'confirmation_status' => OrderConfirmationStatus::NEW,
            // No ecom platform to source a timestamp from — a manually
            // created order was "ordered" at the moment a human entered it.
            'ordered_at' => now(),
            // UC-25: an order is auto-flagged test if any line item's
            // product is itself flagged test — so a merchant's designated
            // test product always produces a test order, without an agent
            // needing to remember to flag it by hand.
            'is_test' => $isTest,
        ]);

        foreach ($data['items'] ?? [] as $item) {
            $variant = isset($item['product_variant_id'])
                ? ProductVariant::find($item['product_variant_id'])
                : null;

            OrderItem::create([
                'business_id' => $businessId,
                'order_id' => $order->id,
                'product_id' => $item['product_id'] ?? null,
                'product_variant_id' => $variant?->id,
                'product_name_snapshot' => $item['product_name'],
                'sku_snapshot' => $variant?->sku,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
            ]);
        }

        if (! empty($data['assigned_agent_id'])) {
            $this->assign($order, (int) $data['assigned_agent_id'], $actor);
        }

        OrderCreated::dispatch($order);

        return $order;
    }

    /**
     * Update an order's customer details and line items. Line items are
     * fully replaced (soft-deleted, then recreated) rather than
     * reconciled by id — an edited order's items have no independent
     * identity elsewhere unless the order has already shipped, which
     * callers must check before reaching this method (see
     * OrderController::update).
     *
     * When $trackUpsell is set (the editor is a confirmation agent), the
     * change in the items total is added to upsell_amount, so the column
     * always reads as "how far this agent moved the order from what came
     * in": positive for an upsell, negative for a down-sell.
     *
     * @param  array{customer_name: string, customer_phone: string, customer_address: string, customer_city?: string|null, source_platform?: string|null, total_amount: float, notes?: string|null, items?: array<int, array{product_id?: int|null, product_variant_id?: int|null, product_name: string, quantity: int, unit_price: float}>}  $data
     */
    public function updateManualOrder(Order $order, array $data, bool $trackUpsell = false): Order
    {
        $customerPhone = PhoneNumber::format($data['customer_phone']) ?? $data['customer_phone'];

        $itemsTotalBefore = $this->itemsTotal($order);

        $order->update([
            'source_platform' => $data['source_platform'] ?? $order->source_platform,
            'customer_name' => $data['customer_name'],
            'customer_phone' => $customerPhone,
            'customer_phone_hash' => hash('sha256', $customerPhone),
            'customer_address' => $data['customer_address'],
            'customer_city' => $data['customer_city'] ?? null,
            'total_amount' => $data['total_amount'],
            // Keyed on presence, not truthiness: omitting `notes` leaves the
            // existing note alone, while an explicit null or empty string
            // clears it. `?? $order->notes` would make clearing impossible.
            'notes' => array_key_exists('notes', $data)
                ? ($data['notes'] ?: null)
                : $order->notes,
        ]);

        $order->items()->delete();

        foreach ($data['items'] ?? [] as $item) {
            $variant = isset($item['product_variant_id'])
                ? ProductVariant::find($item['product_variant_id'])
                : null;

            OrderItem::create([
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'product_id' => $item['product_id'] ?? null,
                'product_variant_id' => $variant?->id,
                'product_name_snapshot' => $item['product_name'],
                'sku_snapshot' => $variant?->sku,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
            ]);
        }

        if ($trackUpsell) {
            $delta = round($this->itemsTotal($order->unsetRelation('items')) - $itemsTotalBefore, 2);

            if ($delta != 0.0) {
                $order->update(['upsell_amount' => round((float) $order->upsell_amount + $delta, 2)]);
            }
        }

        return $order;
    }

    /** Sum of quantity × unit price over the order's current line items. */
    private function itemsTotal(Order $order): float
    {
        return (float) $order->items()
            ->get(['quantity', 'unit_price'])
            ->sum(fn (OrderItem $item) => $item->quantity * (float) $item->unit_price);
    }

    /**
     * Update an order's confirmation status. The only method permitted to
     * change confirmation_status directly (PRD section 7.3) — every
     * transition fires ConfirmationStatusChanged unconditionally, which is
     * what actually writes the order_status_events audit row. Statuses
     * with dedicated business logic additionally fire their own specific
     * event (OrderConfirmed, OrderCancelled, OrderSubmittedToCourier).
     *
     * UC-9: cancelling requires a structured reason code; the `other` code
     * requires an accompanying free-text note. The request layer
     * (UpdateOrderStatusRequest) already enforces this, but it's re-checked
     * here too — a service method must not trust a status transition this
     * consequential to validation that happens outside it.
     *
     * @throws InvalidArgumentException if cancelling without a reason code.
     */
    public function updateStatus(
        Order $order,
        ?User $actor,
        OrderConfirmationStatus $newStatus,
        ?OrderCancelReason $cancellationReasonCode = null,
        ?string $cancellationNote = null,
    ): Order {
        if ($newStatus === $order->confirmation_status) {
            return $order;
        }

        if ($newStatus === OrderConfirmationStatus::CANCELLED && $cancellationReasonCode === null) {
            throw new InvalidArgumentException('A cancellation reason code is required to cancel an order.');
        }

        $fromStatus = $order->confirmation_status;

        // UC-25: a test order reaching CONFIRMED never actually becomes
        // CONFIRMED — it lands directly on the terminal TEST_COMPLETED
        // state instead, so there is no window where a test order looks
        // "ready to ship" to anything downstream (createShipment() also
        // guards this independently, but this is what keeps a test order
        // from ever getting there in the first place).
        if ($newStatus === OrderConfirmationStatus::CONFIRMED && $order->is_test) {
            $newStatus = OrderConfirmationStatus::TEST_COMPLETED;
        }

        $attributes = ['confirmation_status' => $newStatus];

        if ($newStatus === OrderConfirmationStatus::CANCELLED) {
            $attributes['cancellation_reason_code'] = $cancellationReasonCode;
            $attributes['notes'] = $cancellationNote;
        }

        $order->update($attributes);

        ConfirmationStatusChanged::dispatch($order, $fromStatus, $newStatus, $actor);

        if ($newStatus === OrderConfirmationStatus::CONFIRMED) {
            OrderConfirmed::dispatch($order);
        } elseif ($newStatus === OrderConfirmationStatus::CANCELLED) {
            // Non-null here: the guard clause above already rejected a
            // CANCELLED transition with no reason code.
            OrderCancelled::dispatch($order, $cancellationReasonCode);
        } elseif ($newStatus === OrderConfirmationStatus::SUBMITTED_TO_COURIER) {
            OrderSubmittedToCourier::dispatch($order);
        }

        return $order;
    }

    /**
     * Update an order's delivery status. The only method permitted to
     * change delivery_status directly (PRD section 7.3) — every transition
     * fires DeliveryStatusChanged unconditionally, and statuses with
     * dedicated business logic additionally fire their own specific event
     * (OrderDelivered, OrderReturnedInTransit). $actor is null for courier
     * webhook/poll-driven transitions, which have no human actor.
     *
     * Also maintains is_delivery_active alongside delivery_status: true for
     * every non-terminal status, false once the order reaches DELIVERED or
     * CANCELLED_AT_COURIER — the only two terminal statuses. This is the
     * sole place that column is set, so the delivery-status poll
     * (orders:sync-delivery-statuses) can rely on it staying in sync with
     * delivery_status without any other writer to worry about.
     *
     * UC-13/7.4: a transition to RETURNED_IN_TRANSIT requires a structured
     * return reason code, same rule as cancellation on the confirmation
     * side (a separate enum — never merged, since "why did we cancel
     * before shipping" and "why did delivery fail" are different
     * questions with different downstream reactions).
     *
     * @throws InvalidArgumentException if transitioning to RETURNED_IN_TRANSIT without a reason code.
     */
    public function updateDeliveryStatus(
        Order $order,
        OrderDeliveryStatus $newStatus,
        ?User $actor = null,
        ?OrderReturnReason $returnReasonCode = null,
    ): Order {
        if ($newStatus === $order->delivery_status) {
            return $order;
        }

        if ($newStatus === OrderDeliveryStatus::RETURNED_IN_TRANSIT && $returnReasonCode === null) {
            throw new InvalidArgumentException('A return reason code is required to mark an order as returned in transit.');
        }

        $fromStatus = $order->delivery_status;

        $attributes = [
            'delivery_status' => $newStatus,
            'is_delivery_active' => ! in_array($newStatus, [
                OrderDeliveryStatus::DELIVERED,
                OrderDeliveryStatus::CANCELLED_AT_COURIER,
            ], true),
        ];

        if ($newStatus === OrderDeliveryStatus::RETURNED_IN_TRANSIT) {
            $attributes['return_reason_code'] = $returnReasonCode;
        } elseif ($newStatus === OrderDeliveryStatus::READY_FOR_PICKUP) {
            $attributes['ready_for_pickup_at'] = now();
        } elseif ($newStatus === OrderDeliveryStatus::AWAITING_PICKUP) {
            // Moving back to awaiting_pickup means the parcel is no longer
            // staged, so the staging timestamp goes with it — otherwise an
            // undone scan (FulfillmentController::undo) leaves an order that
            // reads as "prepared at 14:32" while sitting in the queue.
            //
            // shipped_at is deliberately left alone: it records courier
            // registration (set once in createShipment), which an undone
            // warehouse scan does not reverse. Reports, daily stats and the
            // parcels list all read it with that meaning.
            $attributes['ready_for_pickup_at'] = null;
        } elseif ($newStatus === OrderDeliveryStatus::RETURN_RECEIVED) {
            $attributes['return_received_at'] = now();
        }

        $order->update($attributes);

        DeliveryStatusChanged::dispatch($order, $fromStatus, $newStatus, $actor);

        if ($newStatus === OrderDeliveryStatus::DELIVERED) {
            OrderDelivered::dispatch($order);
        } elseif ($newStatus === OrderDeliveryStatus::RETURNED_IN_TRANSIT) {
            // Non-null here: the guard clause above already rejected a
            // RETURNED_IN_TRANSIT transition with no reason code.
            OrderReturnedInTransit::dispatch($order, $returnReasonCode);
        } elseif ($newStatus === OrderDeliveryStatus::READY_FOR_PICKUP) {
            ParcelReadyForPickup::dispatch($order);
        } elseif ($newStatus === OrderDeliveryStatus::RETURN_RECEIVED) {
            ParcelReturnReceived::dispatch($order);
        }

        return $order;
    }

    /**
     * Create a shipment for a confirmed order at the chosen delivery
     * courier account (UC-12): persists the customer-info edits and courier
     * choice, registers the parcel with the courier's API, and transitions
     * the order to submitted_to_courier.
     *
     * The order's own reference is sent to the courier as the outbound
     * identifier — OzonExpress accepts it directly as its tracking-number
     * field, Sendit takes it as a free-text reference. courier_tracking_number
     * itself is left untouched until the courier responds: it always holds
     * the courier-assigned identifier and nothing else, so it stays null
     * until this call succeeds.
     *
     * Every courier's own parcel-result DTO shares the same property names
     * (trackingNumber/deliveryCost/returnedCost/refusedCost — see
     * SenditParcelDTO/OzonExpressParcelDTO), so this reads them uniformly
     * regardless of which courier actually answered. OzonExpress quotes
     * returnedCost/refusedCost upfront alongside deliveryCost — what it
     * would instead charge if the parcel isn't successfully delivered;
     * Sendit has no such concept, so those two are always null for it.
     *
     * @param  array{delivery_account_id: int, city_id: int, customer_name: string, customer_phone: string, customer_address: string, total_amount: float, parcel_note?: ?string, parcel_nature?: ?string, parcel_open?: ?bool, parcel_fragile?: ?bool, parcel_replace?: ?bool}  $data
     *
     * @throws InvalidArgumentException if the order isn't confirmed yet, or is a test order.
     * @throws RequestException if the courier's API rejects the parcel.
     * @throws ConnectionException if the courier's API can't be reached at all.
     */
    public function createShipment(
        Order $order,
        User $actor,
        array $data
    ): Order {
        // UC-25: a test order must never reach a real delivery courier,
        // checked here (before any connection service is touched) as the
        // hard backstop — updateStatus() already keeps a test order from
        // ever reaching CONFIRMED, so this should be unreachable in
        // practice, but this method is the one place that actually calls
        // out to a courier and must never trust that alone.
        if ($order->is_test) {
            throw new InvalidArgumentException('Test orders cannot be shipped to a delivery courier.');
        }

        if ($order->confirmation_status !== OrderConfirmationStatus::CONFIRMED) {
            throw new InvalidArgumentException('Only confirmed orders can be shipped.');
        }

        $account = DeliveryAccount::where('business_id', $order->business_id)
            ->with(['courier', 'collectCity'])
            ->findOrFail($data['delivery_account_id']);

        $city = DeleveryCourrierCity::where('courrier_id', $account->courier_id)
            ->findOrFail($data['city_id']);

        $customerPhone = PhoneNumber::format($data['customer_phone']) ?? $data['customer_phone'];

        $order->update([
            'customer_name' => $data['customer_name'],
            'customer_phone' => $customerPhone,
            'customer_phone_hash' => hash('sha256', $customerPhone),
            'customer_address' => $data['customer_address'],
            'customer_city' => $city->name,
            'total_amount' => $data['total_amount'],
            'delivery_account_id' => $account->id,
            'parcel_note' => $data['parcel_note'] ?? null,
            'parcel_nature' => $data['parcel_nature'] ?? null,
            'parcel_open' => $data['parcel_open'] ?? null,
            'parcel_fragile' => $data['parcel_fragile'] ?? null,
            'parcel_replace' => $data['parcel_replace'] ?? null,
            'parcel_products' => $this->parcelProducts->build($order),
        ]);

        try {
            $parcel = $this->manager->courierForAccount($account)->addParcel($order, $city);
        } catch (RequestException $e) {
            OrderDeliveryCreationFailed::dispatch($order, $e->getMessage());

            throw $e;
        }

        $order->update([
            'courier_tracking_number' => $parcel->trackingNumber ?: $order->courier_tracking_number,
            'delivery_cost' => $parcel->deliveryCost,
            'returned_cost' => $parcel->returnedCost,
            'refused_cost' => $parcel->refusedCost,
            'courier_slug' => $account->courier->slug,
            'shipped_at' => now(),
        ]);

        $this->updateStatus($order, $actor, OrderConfirmationStatus::SUBMITTED_TO_COURIER);
        $this->updateDeliveryStatus($order, OrderDeliveryStatus::AWAITING_PICKUP, $actor);

        return $order;
    }

    /**
     * List the cities covered by a delivery account's courier, for a
     * dependent city dropdown/picker.
     *
     * @return Collection<int, DeleveryCourrierCity>
     */
    public function citiesForDeliveryAccount(DeliveryAccount $deliveryAccount)
    {
        return DeleveryCourrierCity::where('courrier_id', $deliveryAccount->courier_id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Assign (or unassign) an order to an agent. Fires OrderAssigned only
     * when an agent is actually being set (not cleared) and the assignment
     * is actually changing — the seam for UC-21's "new order assigned"
     * push notification.
     *
     * confirmation_status is never manually set to "assigned" (it's hidden
     * from the status changer) — it's only ever derived here, from the
     * assignment itself: a new order becomes assigned once an agent is set,
     * and reverts to new if that agent is cleared. This only applies while
     * the order is still in the new/assigned pair — reassigning or
     * unassigning an order that's already moved on (confirmed, cancelled,
     * submitted_to_courier, ...) changes who owns it without touching its
     * pipeline status. $actor is null for system-driven assignment (the
     * auto-assign-on-create listener has no human actor).
     */
    public function assign(Order $order, ?int $assignedAgentId, ?User $actor = null): Order
    {
        if ($assignedAgentId === $order->assigned_agent_id) {
            return $order;
        }

        $order->update(['assigned_agent_id' => $assignedAgentId]);

        if ($assignedAgentId !== null) {
            $agent = User::findOrFail($assignedAgentId);

            OrderAssigned::dispatch($order, $agent);

            if ($order->confirmation_status === OrderConfirmationStatus::NEW) {
                $this->updateStatus($order, $actor, OrderConfirmationStatus::ASSIGNED);
            }
        } elseif ($order->confirmation_status === OrderConfirmationStatus::ASSIGNED) {
            $this->updateStatus($order, $actor, OrderConfirmationStatus::NEW);
        }

        return $order;
    }

    /**
     * Soft-delete an order.
     */
    public function destroy(Order $order): void
    {
        $order->delete();
    }

    /**
     * Soft-delete several orders at once, scoped to the given business so a
     * crafted id list can't touch another business's orders.
     *
     * @param  array<int, int>  $ids
     */
    public function bulkDestroy(int $businessId, array $ids): int
    {
        return Order::where('business_id', $businessId)
            ->whereIn('id', $ids)
            ->delete();
    }

    /**
     * Assign (or unassign) multiple orders at once, scoped to the caller's business.
     *
     * @param  array<int, int>  $ids
     */
    public function bulkAssign(int $businessId, array $ids, ?int $assignedAgentId, ?User $actor = null): int
    {
        $orders = Order::where('business_id', $businessId)
            ->whereIn('id', $ids)
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            $this->assign($order, $assignedAgentId, $actor);
            $count++;
        }

        return $count;
    }

    /**
     * Update confirmation status for multiple orders at once, scoped to the caller's business.
     *
     * @param  array<int, int>  $ids
     */
    public function bulkUpdateStatus(int $businessId, array $ids, OrderConfirmationStatus $newStatus, ?User $actor = null): int
    {
        $orders = Order::where('business_id', $businessId)
            ->whereIn('id', $ids)
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            if ($order->confirmation_status !== $newStatus) {
                $this->updateStatus($order, $actor, $newStatus);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Load orders live from an ecom platform and persist them, upserting on
     * (store_id, external_order_id) so re-syncing never duplicates rows.
     *
     * Order line items are matched against this business's already-synced
     * products, so a store whose products haven't been synced yet is
     * skipped (its name is reported back) rather than silently creating
     * orders whose items can't be linked to a product.
     *
     * `$storeId` narrows the sync to a single store; null means every
     * connected store matching the platform filter.
     *
     * @return array{synced: int, stores_missing_products: array<int, string>}
     */
    public function syncFromPlatform(int $businessId, string $platformSlug, ?int $storeId = null): array
    {
        $stores = Store::where('business_id', $businessId)
            ->where('connection_status', StoreConnectionStatus::CONNECTED)
            // $businessId above already scopes this to the tenant, so a
            // forged id can only ever narrow to a store they own.
            ->when($storeId, fn ($query) => $query->whereKey($storeId))
            ->when(
                $platformSlug !== '*',
                fn ($query) => $query->whereHas('platform', fn ($platformQuery) => $platformQuery->where('slug', $platformSlug))
            )
            ->with('platform')
            ->get();

        $synced = 0;
        $storesMissingProducts = [];

        foreach ($stores as $store) {
            try {
                $synced += $this->orderSync->syncStore($store);
            } catch (RuntimeException $e) {
                Log::critical($e->getMessage(), [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                ]);
                $storesMissingProducts[] = $store->name;
            }
        }

        return [
            'synced' => $synced,
            'stores_missing_products' => $storesMissingProducts,
        ];
    }
}
