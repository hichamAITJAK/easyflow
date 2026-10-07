<?php

namespace App\Services\Operations\Products;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Operations\IntegrationManagerService;
use App\Support\StoreConnectionHealth;
use InvalidArgumentException;
use Throwable;

/**
 * Pulls products from a store's ecom platform and upserts them into our
 * products table. Shared by the manual "sync" action
 * (ProductController::sync) and the connect-flow's initial sync, so both
 * stay in lockstep with the same upsert rules.
 */
class ProductSyncService
{
    public function __construct(
        private readonly IntegrationManagerService $manager = new IntegrationManagerService,
    ) {}

    /**
     * Sync every product for a single store, upserting on
     * (store_id, external_product_id). Returns the number of products synced.
     */
    /**
     * The full catalog is imported deliberately, with no date cutoff: a new
     * order can reference a product created long before the store was
     * connected, and order line items match our `products` table by external
     * product id. Skipping older products would leave those line items
     * unlinked (null product_id, no is_test detection). Only orders carry a
     * connection-date cutoff — see OrderSyncService.
     */
    public function syncStore(Store $store): int
    {
        try {
            $products = $this->manager->ecomPlatformForStore($store)->loadProducts();
        } catch (InvalidArgumentException) {
            return 0;
        } catch (Throwable $exception) {
            // A rejected credential marks the store so the merchant is asked
            // to reconnect; anything else (platform outage, timeout) is left
            // to the caller's own retry handling.
            StoreConnectionHealth::recordFailure($store, $exception);

            throw $exception;
        }

        foreach ($products as $product) {
            $this->upsertProduct($store, $product->toArray());
        }

        return count($products);
    }

    /**
     * Upsert a single platform product into our products table, keyed on
     * (store_id, external_product_id) so re-syncing updates in place rather
     * than creating duplicates. Also syncs the product's variants.
     *
     * @param  array<string, mixed>  $data
     */
    private function upsertProduct(Store $store, array $data): void
    {
        $variants = $data['variants'] ?? [];
        $price = $variants[0]['price'] ?? $data['price'] ?? null;
        $isActive = isset($data['status'])
            ? in_array($data['status'], ['active', true, 1], true)
            : true;

        $product = Product::updateOrCreate(
            [
                'store_id' => $store->id,
                'external_product_id' => (string) ($data['id'] ?? ''),
            ],
            [
                'business_id' => $store->business_id,
                'name' => $data['title'] ?? $data['name'] ?? 'Untitled product',
                'description' => $data['description'] ?? null,
                'price' => $price,
                'public_url' => $data['public_url'] ?? $data['url'] ?? null,
                'thumbnail' => $data['image_url'] ?? $data['thumbnail'] ?? null,
                'tags' => $data['tags'] ?? null,
                'status' => $isActive,
                'is_active' => $isActive,
            ],
        );

        $this->syncVariants($store, $product, $variants);
    }

    /**
     * Upsert a product's variants, keyed on (product_id, external_variant_id).
     * Each variant's option name/value pairs (e.g. ['Size' => 'M']) become
     * ProductOption/ProductOptionValue rows shared across the product's
     * variants, linked via the product_variant_option_values pivot.
     *
     * Platforms that don't expose structured option data fall back to a
     * single generic "Variant" option built from the variant's title, so
     * distinct variants of the same product still resolve to distinct
     * option values instead of colliding.
     *
     * Every platform-owned field is overwritten on each sync except `sku`,
     * which is local courier-mapping data (see the note at the upsert).
     *
     * Re-syncing replaces the variant set wholesale, matching the pattern
     * used for order line items in OrderSyncService.
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(Store $store, Product $product, array $variants): void
    {
        $optionsByName = [];
        $keptVariantIds = [];

        foreach ($variants as $data) {
            $variant = ProductVariant::withTrashed()->firstOrNew([
                'product_id' => $product->id,
                'external_variant_id' => (string) ($data['id'] ?? ''),
            ]);

            $variant->fill([
                'business_id' => $store->business_id,
                'price' => $data['price'] ?? null,
                'is_available' => $data['available'] ?? true,
            ]);

            // Stock counted in EasyFlow's warehouse wins over the platform's
            // figure once someone has edited it here (see
            // ProductInventoryController); until then the platform seeds it.
            if (! $product->stock_managed_locally) {
                $variant->inventory_quantity = $data['inventory_quantity'] ?? null;
            }

            // sku is synced-immune, mirroring the product-level sku the
            // product upsert above already omits: it is the local
            // courier-mapping identifier and is never pushed back to the
            // platform, so a re-sync must not clobber an operator's edit.
            // Only seeded from the platform when we have nothing yet,
            // which keeps first-sync behaviour unchanged.
            if ($variant->sku === null) {
                $variant->sku = $data['sku'] ?? null;
            }

            $variant->save();

            if ($variant->trashed()) {
                $variant->restore();
            }

            $keptVariantIds[] = $variant->id;

            $optionValues = $data['option_values'] ?? [];
            if ($optionValues === [] && count($variants) > 1 && filled($data['title'] ?? null)) {
                $optionValues = ['Variant' => $data['title']];
            }

            $valueIds = [];
            foreach ($optionValues as $name => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $option = $optionsByName[$name] ??= ProductOption::firstOrCreate(
                    ['product_id' => $product->id, 'name' => $name],
                    ['business_id' => $store->business_id],
                );

                $optionValue = ProductOptionValue::firstOrCreate(
                    ['product_option_id' => $option->id, 'value' => (string) $value],
                    ['business_id' => $store->business_id],
                );

                $valueIds[] = $optionValue->id;
            }

            $variant->optionValues()->sync($valueIds);
        }

        $product->variants()->whereNotIn('id', $keptVariantIds)->delete();
    }
}
