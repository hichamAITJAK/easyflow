<?php

namespace App\Services\Operations\Products;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Persists a manually-added product's image gallery from the ordered list
 * of paths the product form submits, and keeps `products.thumbnail` in sync
 * with the first image — nothing else references a specific image row (no
 * order_items-style foreign key), so the gallery is simply replaced on every
 * save rather than reconciled row-by-row like ProductVariantWriter.
 */
class ProductImageWriter
{
    /**
     * @param  array<int, string>  $paths
     */
    public function write(Product $product, array $paths): void
    {
        DB::transaction(function () use ($product, $paths) {
            $product->images()->delete();

            foreach (array_values($paths) as $position => $path) {
                $product->images()->create([
                    'business_id' => $product->business_id,
                    'path' => $path,
                    'position' => $position,
                ]);
            }

            $product->update(['thumbnail' => $paths[0] ?? null]);
        });
    }
}
