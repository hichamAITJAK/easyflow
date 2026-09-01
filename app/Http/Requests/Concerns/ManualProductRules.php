<?php

namespace App\Http\Requests\Concerns;

/**
 * Validation rules for a manually-added product — the full editable field
 * set plus its optional variant structure. Shared by StoreProductRequest
 * and UpdateProductRequest so the create and manual-edit paths stay in
 * lockstep.
 *
 * Variant shape: `options` declares each option and its values (Size:
 * S/M/L), and `variants` carries one row per option-value combination
 * (its own sku/price/inventory), each keyed back to its values by the
 * `options` map ({"Size": "M"}).
 *
 * Gallery shape: `images` is an ordered list of storage paths (from
 * ProductController::uploadImage) — position in the array is the display
 * order, and the first path doubles as the product's thumbnail (see
 * ProductImageWriter).
 */
trait ManualProductRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function manualProductRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'inventory_quantity' => ['nullable', 'integer', 'min:0'],
            'public_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'is_test' => ['nullable', 'boolean'],

            'images' => ['nullable', 'array'],
            'images.*' => ['required', 'string', 'max:2048'],

            'options' => ['nullable', 'array'],
            'options.*.name' => ['required_with:options', 'string', 'max:255'],
            'options.*.values' => ['required_with:options', 'array', 'min:1'],
            'options.*.values.*' => ['required', 'string', 'max:255'],

            'variants' => ['nullable', 'array'],
            'variants.*.options' => ['required_with:variants', 'array'],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.image' => ['nullable', 'string', 'max:2048'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.inventory_quantity' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_available' => ['nullable', 'boolean'],
        ];
    }
}
