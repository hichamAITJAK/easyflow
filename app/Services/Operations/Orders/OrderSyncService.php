<?php

namespace App\Services\Operations\Orders;

use App\Enums\OrderConfirmationStatus;
use App\Events\Order\OrderCreated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Operations\IntegrationManagerService;
use App\Services\Operations\Products\SkuResolver;
use App\Support\PhoneNumber;
use App\Support\StoreConnectionHealth;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Pulls orders from a store's ecom platform and upserts them into our
 * orders table. Shared by the manual "sync" action (ProductController's
 * order counterpart, OrderController::sync) and the connect-flow's
 * initial sync, so both stay in lockstep with the same upsert rules.
 *
 * Order line items reference our own `products` table by
 * external_product_id, so a store's products must already be synced
 * (via ProductSyncService) before its orders can be synced — otherwise
 * there would be nothing to match line items against.
 */
class OrderSyncService
{
    public function __construct(
        private readonly IntegrationManagerService $manager = new IntegrationManagerService,
        private readonly OrderCodeGenerator $codeGenerator = new OrderCodeGenerator,
    ) {}

    /**
     * Sync every order for a single store, upserting on
     * (store_id, external_order_id). Returns the number of orders synced.
     *
     * @throws RuntimeException if the store has no synced products yet.
     */
    public function syncStore(Store $store): int
    {
        try {
            $orders = $this->manager->ecomPlatformForStore($store)->loadOrders($store->created_at);
        } catch (InvalidArgumentException) {
            return 0;
        } catch (Throwable $exception) {
            // A rejected credential marks the store so the merchant is asked
            // to reconnect; anything else (platform outage, timeout) is left
            // to the caller's own retry handling.
            StoreConnectionHealth::recordFailure($store, $exception);

            throw $exception;
        }

        // Second line of defence behind the API-side date filter. Only
        // Shopify and YouCan can filter orders server-side; Lightfunnels
        // can't, and a platform that silently ignores an unsupported filter
        // would otherwise import the store's entire history. Enforcing the
        // cutoff here means the guarantee never depends on the remote API
        // honouring it.
        $orders = $this->rejectOrdersBefore($store, $orders);

        if ($orders !== [] && ! Product::where('store_id', $store->id)->exists()) {
            throw new RuntimeException(
                "Store [{$store->name}] has no synced products yet — sync its products before syncing orders."
            );
        }

        $productsByExternalId = Product::where('store_id', $store->id)
            ->get(['id', 'external_product_id', 'is_test'])
            ->keyBy('external_product_id');

        $variantsByProductId = $this->loadVariantsByProductId($productsByExternalId);

        foreach ($orders as $order) {
            $this->upsertOrder($store, $order->toArray(), $productsByExternalId, $variantsByProductId);
        }

        return count($orders);
    }

    /**
     * Upsert a single already-normalized order (e.g. a webhook payload run
     * through the platform's OrderDTO) into our orders table. Unlike
     * syncStore(), this doesn't require every one of the store's products to
     * already be synced — a line item whose product isn't found yet simply
     * lands with a null product_id, matching upsertOrder()'s existing
     * null-safe behavior. Callers that care about that gap (e.g. a webhook
     * arriving before the first product sync) should check for it
     * themselves before calling this.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncOne(Store $store, array $data): Order
    {
        $productsByExternalId = Product::where('store_id', $store->id)
            ->get(['id', 'external_product_id', 'is_test'])
            ->keyBy('external_product_id');

        $variantsByProductId = $this->loadVariantsByProductId($productsByExternalId);

        return $this->upsertOrder($store, $data, $productsByExternalId, $variantsByProductId);
    }

    /**
     * Drop orders placed before the store was connected to EasyFlow, so a
     * newly connected store imports the business it does from now on rather
     * than its entire trading history.
     *
     * An order with an unparseable or missing creation timestamp is KEPT: a
     * real order that we can't date is a worse thing to lose than an old one
     * is to import, and this path also covers webhook-shaped payloads whose
     * timestamps vary by platform.
     *
     * @param  array<int, Arrayable<string, mixed>>  $orders
     * @return array<int, Arrayable<string, mixed>>
     */
    private function rejectOrdersBefore(Store $store, array $orders): array
    {
        $cutoff = $store->created_at;

        if ($cutoff === null) {
            return $orders;
        }

        return array_values(array_filter($orders, function ($order) use ($store, $cutoff) {
            $createdAt = OrderedAtParser::parse(
                $store->platform?->slug,
                $order->toArray()['created_at'] ?? null,
            );

            return $createdAt === null || $createdAt->greaterThanOrEqualTo($cutoff);
        }));
    }

    /**
     * Preload every variant for the given products, grouped by product_id,
     * so line-item-to-variant matching in syncOrderItems() doesn't issue a
     * query per line item.
     *
     * @param  Collection<string, Product>  $productsByExternalId
     * @return Collection<int, EloquentCollection<int, ProductVariant>>
     */
    private function loadVariantsByProductId(Collection $productsByExternalId): Collection
    {
        return ProductVariant::whereIn('product_id', $productsByExternalId->pluck('id'))
            ->get()
            ->groupBy('product_id');
    }

    /**
     * Upsert a single platform order into our orders table, keyed on
     * (store_id, external_order_id) so re-syncing updates in place rather
     * than creating duplicates. Also syncs the order's line items.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<string, Product>  $productsByExternalId
     * @param  Collection<int, EloquentCollection<int, ProductVariant>>  $variantsByProductId
     */
    private function upsertOrder(Store $store, array $data, $productsByExternalId, $variantsByProductId): Order
    {
        $shipping = $data['shipping_address'] ?? [];

        $customerName = $data['customer_name']
            ?: trim(($shipping['first_name'] ?? '').' '.($shipping['last_name'] ?? ''))
            ?: 'Unknown customer';

        $rawCustomerPhone = $data['customer_phone'] ?? $shipping['phone'] ?? '';
        $customerPhone = PhoneNumber::format($rawCustomerPhone) ?? $rawCustomerPhone;

        $customerAddress = implode(', ', array_filter([
            $shipping['first_line'] ?? null,
            $shipping['second_line'] ?? null,
        ])) ?: '—';

        $lookup = [
            'store_id' => $store->id,
            'external_order_id' => (string) ($data['id'] ?? ''),
        ];

        $values = [
            'business_id' => $store->business_id,
            'source_platform' => $store->platform->slug ?? 'manual',
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_phone_hash' => hash('sha256', $customerPhone),
            'customer_address' => $customerAddress,
            'customer_city' => $shipping['city'] ?? null,
            'total_amount' => $data['total'] ?? 0,
            'delivery_cost' => $data['delivery_cost'] ?? $data['shipping_cost'] ?? 0,
            'ordered_at' => OrderedAtParser::parse($store->platform?->slug, $data['created_at'] ?? null),
            'raw_payload' => $data,
        ];

        // withTrashed(): a soft-deleted order still occupies the
        // (store_id, external_order_id) unique slot at the DB level, but
        // exists()'s check excludes trashed rows by default — without this
        // it would try an INSERT and collide with the trashed row's unique
        // constraint instead of updating it.
        $isNew = ! Order::withTrashed()->where($lookup)->exists();

        // confirmation_status is intentionally excluded from $values above:
        // it must only ever be set on insert, never on update — re-syncing
        // an existing order must never reset a status a call-center agent
        // has already moved forward (confirmation is a call-center concept
        // the platform has no notion of, so every fresh sync starts here
        // regardless of the platform's own payment/fulfillment status).
        //
        // is_test is likewise insert-only (UC-25): derived once, from
        // whether any line item's matched product is itself flagged test,
        // at the moment this order first arrives — not re-evaluated on
        // every re-sync, same as the duplicate/blacklist checks it feeds.
        if ($isNew) {
            $values['confirmation_status'] = OrderConfirmationStatus::NEW->value;
            $values['is_test'] = $this->hasTestProduct($data['line_items'] ?? [], $productsByExternalId);
        }

        $order = Order::withTrashed()->updateOrCreate($lookup, $values);

        // deleted_at isn't mass-assignable, so a re-synced order that was
        // previously (soft-)deleted needs an explicit restore to become
        // visible again.
        if ($order->trashed()) {
            $order->restore();
        }

        // Only a brand-new row needs a reference minted — re-syncing an
        // existing order must never touch its identifiers.
        if ($order->wasRecentlyCreated) {
            $order->update([
                'reference' => $this->codeGenerator->reference(),
            ]);

            OrderCreated::dispatch($order);
        }

        $this->syncOrderItems($store, $order, $data['line_items'] ?? [], $productsByExternalId, $variantsByProductId);

        return $order;
    }

    /**
     * Whether any of an order's line items resolve to a product flagged
     * test (UC-25) — same product lookup as syncOrderItems(), kept
     * separate since this runs before the order row (and thus its items)
     * exist.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  Collection<string, Product>  $productsByExternalId
     */
    private function hasTestProduct(array $lineItems, $productsByExternalId): bool
    {
        foreach ($lineItems as $lineItem) {
            $product = $productsByExternalId->get((string) ($lineItem['product_id'] ?? ''));

            if ($product?->is_test) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upsert an order's line items, matching each one to our own `products`
     * row via the platform's product id, and to a `product_variants` row
     * via the platform's variant id (falling back to a sku match, for
     * platforms whose order payload doesn't carry a variant id). Re-syncing
     * replaces the item set wholesale, since the platform is the source of
     * truth for line items.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  Collection<string, Product>  $productsByExternalId
     * @param  Collection<int, EloquentCollection<int, ProductVariant>>  $variantsByProductId
     */
    private function syncOrderItems(Store $store, Order $order, array $lineItems, $productsByExternalId, $variantsByProductId): void
    {
        $keptItemIds = [];

        foreach ($lineItems as $lineItem) {
            $product = $productsByExternalId->get((string) ($lineItem['product_id'] ?? ''));
            $variant = $this->matchVariant($product, $lineItem, $variantsByProductId);

            // Folding the variant title into the snapshot (rather than
            // matching on product_id alone) keeps two different variants of
            // the same product from colliding onto a single order_items row.
            $title = (string) ($lineItem['title'] ?? 'Unknown product');
            $variantTitle = $lineItem['variant_title'] ?? null;
            $nameSnapshot = $variantTitle ? "{$title} ({$variantTitle})" : $title;

            $skuSnapshot = $variant->sku ?? $lineItem['sku'] ?? null;
            if ($skuSnapshot === null && $product) {
                $skuSnapshot = SkuResolver::resolve($product, null, $variantTitle);
            }

            $item = OrderItem::withTrashed()->updateOrCreate(
                [
                    'order_id' => $order->id,
                    'product_id' => $product?->id,
                    'product_name_snapshot' => $nameSnapshot,
                ],
                [
                    'business_id' => $store->business_id,
                    'product_variant_id' => $variant?->id,
                    'sku_snapshot' => $skuSnapshot,
                    'quantity' => (int) ($lineItem['quantity'] ?? 1),
                    'unit_price' => (float) ($lineItem['price'] ?? 0),
                ],
            );

            if ($item->trashed()) {
                $item->restore();
            }

            $keptItemIds[] = $item->id;
        }

        // Re-syncing replaces the line item set wholesale — anything no
        // longer present on the platform's order is soft-deleted here.
        $order->items()->whereNotIn('id', $keptItemIds)->delete();
    }

    /**
     * Match a line item to one of its product's variants: primarily by the
     * platform's variant id, falling back to a sku match for platforms
     * (e.g. Lightfunnels) whose order payload doesn't carry a variant id.
     *
     * @param  array<string, mixed>  $lineItem
     * @param  Collection<int, EloquentCollection<int, ProductVariant>>  $variantsByProductId
     */
    private function matchVariant(?Product $product, array $lineItem, $variantsByProductId): ?ProductVariant
    {
        if (! $product) {
            return null;
        }

        $variantsForProduct = $variantsByProductId->get($product->id) ?? collect();

        $externalVariantId = $lineItem['variant_id'] ?? null;
        if ($externalVariantId !== null) {
            $variant = $variantsForProduct->firstWhere('external_variant_id', (string) $externalVariantId);
            if ($variant) {
                return $variant;
            }
        }

        if (! empty($lineItem['sku'])) {
            return $variantsForProduct->firstWhere('sku', $lineItem['sku']);
        }

        return null;
    }
}
