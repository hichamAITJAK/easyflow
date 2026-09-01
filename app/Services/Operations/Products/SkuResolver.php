<?php

namespace App\Services\Operations\Products;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Resolves the sku to snapshot onto an order item: the variant's own sku
 * when it has one, otherwise a generated fallback built from the product's
 * sku (or name) plus the variant's title, so every order item still ends
 * up with a usable identifier even when the source platform never set a
 * sku on the variant.
 */
class SkuResolver
{
    public static function resolve(Product $product, ?string $variantSku, ?string $variantTitle): ?string
    {
        if (filled($variantSku)) {
            return $variantSku;
        }

        $base = $product->sku ?: Str::slug($product->name);
        if (blank($base)) {
            return null;
        }

        return filled($variantTitle) ? $base.'-'.Str::slug($variantTitle) : $base;
    }
}
