<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Concerns\ScopesAgentAccess;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductInventoryController extends Controller
{
    use ScopesAgentAccess;

    /**
     * Set the stock of a product (when it has no variants) or of each of
     * its variants. Marks the product as locally managed so the next
     * platform sync does not overwrite the count.
     *
     * Payload: { inventory_quantity?: int|null, variants?: { [variantId]: int|null } }
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        $user = $request->user();

        abort_unless($product->business_id === $user->business_id, 403);
        abort_unless(
            $this->applyProductScope(Product::whereKey($product->getKey()), $user)->exists(),
            403,
        );

        $validated = $request->validate([
            'inventory_quantity' => ['nullable', 'integer', 'min:0'],
            'variants' => ['nullable', 'array'],
            'variants.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $variantQuantities = $validated['variants'] ?? [];

        if ($variantQuantities !== []) {
            // Through the product's own relation, so a forged id matches
            // nothing instead of editing another product's variant.
            $variants = $product->variants()
                ->whereIn('id', array_map('intval', array_keys($variantQuantities)))
                ->get();

            foreach ($variants as $variant) {
                /** @var ProductVariant $variant */
                $quantity = $variantQuantities[$variant->id] ?? $variantQuantities[(string) $variant->id] ?? null;
                $variant->update(['inventory_quantity' => $quantity]);
            }
        } elseif (array_key_exists('inventory_quantity', $validated)) {
            $product->inventory_quantity = $validated['inventory_quantity'];
        }

        $product->stock_managed_locally = true;
        $product->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock updated.')]);

        return back(fallback: route('products.index'));
    }
}
