<?php

namespace App\Services\Operations\Products;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Persists the option/value/variant graph for a manually-added product
 * from the flat payload the product form submits.
 *
 * The payload is the source of truth, so the stored graph is reconciled to
 * match it on every save: options and their values are upserted (and any
 * dropped ones removed), then each submitted variant is matched to an
 * existing one by its exact set of option values — so an untouched
 * combination keeps its row (and any order_items still pointing at it)
 * rather than being deleted and recreated with a fresh id. Combinations no
 * longer present are soft-deleted.
 */
class ProductVariantWriter
{
    /**
     * @param  array<int, array{name: string, values: array<int, string>}>  $options
     * @param  array<int, array{options: array<string, string>, sku?: ?string, image?: ?string, price?: string|float|null, inventory_quantity?: ?int, is_available?: ?bool}>  $variants
     */
    public function write(Product $product, array $options, array $variants): void
    {
        DB::transaction(function () use ($product, $options, $variants) {
            $valueIds = $this->syncOptions($product, $options);
            $this->syncVariants($product, $variants, $valueIds);
        });
    }

    /**
     * Upsert the product's options and their values, dropping any no longer
     * present, and return a lookup of [optionName][value] => option_value id
     * for wiring variants up to their values.
     *
     * @param  array<int, array{name: string, values: array<int, string>}>  $options
     * @return array<string, array<string, int>>
     */
    private function syncOptions(Product $product, array $options): array
    {
        $keptOptionIds = [];
        $valueIndex = [];

        foreach (array_values($options) as $position => $option) {
            $name = $option['name'];

            $optionModel = ProductOption::firstOrCreate(
                ['product_id' => $product->id, 'name' => $name],
                ['business_id' => $product->business_id],
            );
            $optionModel->update(['position' => $position]);
            $keptOptionIds[] = $optionModel->id;

            $keptValueIds = [];
            foreach (array_values($option['values']) as $valuePosition => $value) {
                $valueModel = ProductOptionValue::firstOrCreate(
                    ['product_option_id' => $optionModel->id, 'value' => $value],
                    ['business_id' => $product->business_id],
                );
                $valueModel->update(['position' => $valuePosition]);

                $keptValueIds[] = $valueModel->id;
                $valueIndex[$name][$value] = $valueModel->id;
            }

            // Values dropped from this option — hard delete, which cascades
            // their product_variant_option_values pivot rows.
            ProductOptionValue::where('product_option_id', $optionModel->id)
                ->whereNotIn('id', $keptValueIds)
                ->delete();
        }

        $product->options()->whereNotIn('id', $keptOptionIds)->delete();

        return $valueIndex;
    }

    /**
     * Upsert the product's variants, matching each submitted combination to
     * an existing variant by its exact set of option-value ids so untouched
     * rows are preserved. Combinations no longer submitted are soft-deleted.
     *
     * @param  array<int, array{options: array<string, string>, sku?: ?string, image?: ?string, price?: string|float|null, inventory_quantity?: ?int, is_available?: ?bool}>  $variants
     * @param  array<string, array<string, int>>  $valueIndex
     */
    private function syncVariants(Product $product, array $variants, array $valueIndex): void
    {
        $existingBySignature = $product->variants()
            ->withTrashed()
            ->with('optionValues:id')
            ->get()
            ->keyBy(fn (ProductVariant $variant) => $this->signature($variant->optionValues->pluck('id')->all()));

        $keptIds = [];

        foreach ($variants as $data) {
            $valueIdsForVariant = [];
            foreach ($data['options'] as $optionName => $value) {
                $id = $valueIndex[$optionName][$value] ?? null;
                if ($id !== null) {
                    $valueIdsForVariant[] = $id;
                }
            }

            $signature = $this->signature($valueIdsForVariant);

            $attributes = [
                'business_id' => $product->business_id,
                'sku' => $data['sku'] ?? null,
                'image' => $data['image'] ?? null,
                'price' => $data['price'] ?? null,
                'inventory_quantity' => $data['inventory_quantity'] ?? null,
                'is_available' => $data['is_available'] ?? true,
            ];

            $variant = $existingBySignature->get($signature);

            if ($variant) {
                if ($variant->trashed()) {
                    $variant->restore();
                }
                $variant->update($attributes);
            } else {
                $variant = $product->variants()->create($attributes);
                $variant->optionValues()->sync($valueIdsForVariant);
            }

            $keptIds[] = $variant->id;
        }

        $product->variants()->whereNotIn('id', $keptIds)->delete();
    }

    /**
     * Order-independent signature for a set of option-value ids, so a
     * combination matches regardless of the order its values arrive in.
     *
     * @param  array<int, int>  $valueIds
     */
    private function signature(array $valueIds): string
    {
        sort($valueIds);

        return implode('-', $valueIds);
    }
}
