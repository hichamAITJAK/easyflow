<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ManualProductRules;
use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Updates a product. A manually-added product (store_id null) is fully
 * customizable, variants included. A store-synced product is platform-owned
 * — the platform's next sync overwrites name/price/description/variants/etc.
 * anyway, so only sku, variant_skus, inventory_quantity, is_active, and
 * is_test are editable locally; both the product-level sku and per-variant
 * SKUs are synced-immune (courier-mapping only, never pushed back to the
 * platform), while inventory_quantity is not — a manual edit there is
 * simply lost on the product's next sync.
 */
class UpdateProductRequest extends FormRequest
{
    use ManualProductRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && $product->business_id === $this->user()->business_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        if (! $product instanceof Product) {
            return $this->manualProductRules();
        }

        if ($product->store_id === null) {
            return $this->manualProductRules();
        }

        return [
            'sku' => ['nullable', 'string', 'max:255'],
            'inventory_quantity' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_test' => ['nullable', 'boolean'],

            // Variant SKUs are editable on a synced product for the same
            // reason the product-level one is: they are local
            // courier-mapping identifiers, never pushed to the platform,
            // and ProductSyncService leaves them alone on re-sync. Keyed by
            // variant id — the platform owns which variants exist, so this
            // can only ever relabel an existing row, never add or remove
            // one. Ownership of each id is enforced in the controller.
            'variant_skus' => ['nullable', 'array'],
            'variant_skus.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
