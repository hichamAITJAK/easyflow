<?php

namespace App\Services\Operations\Products;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves warehouse stock with the parcel: an order's items leave stock when
 * the parcel is scanned out (ready for pickup) and come back if that scan
 * is undone. Every move is a StockMovement row (type `sale`, then
 * `sale_reversal`), which is also what makes both calls safe to repeat:
 * a line is only taken once until it is given back.
 *
 * A line takes stock from its variant when it has one, else from the
 * product when that product has no variants. Untracked stock (a null
 * quantity) is left alone, and stock never goes below zero: the movement
 * records what was actually removed, so an undo puts back exactly that.
 */
class OrderStockService
{
    public const SALE = 'sale';

    public const SALE_REVERSAL = 'sale_reversal';

    /**
     * Take the order's items out of stock.
     *
     * @return list<array{product: string, variant: string|null, sku: string|null, ordered: int, change: int, before: int, after: int}>
     */
    public function deductForOrder(Order $order): array
    {
        return DB::transaction(function () use ($order) {
            $changes = [];

            foreach ($this->items($order) as $item) {
                if ($this->isOut($item)) {
                    continue;
                }

                $target = $this->lockedTarget($item);

                if ($target === null) {
                    continue;
                }

                $before = (int) $target->inventory_quantity;
                $removed = min($item->quantity, max($before, 0));
                $target->update(['inventory_quantity' => $before - $removed]);

                $this->record($order, $item, $target, self::SALE, -$removed);
                $changes[] = $this->change($item, $item->quantity, -$removed, $before);
            }

            return $changes;
        });
    }

    /**
     * Put back what deductForOrder() took, line by line.
     *
     * @return list<array{product: string, variant: string|null, sku: string|null, ordered: int, change: int, before: int, after: int}>
     */
    public function restoreForOrder(Order $order): array
    {
        return DB::transaction(function () use ($order) {
            $changes = [];

            foreach ($this->items($order) as $item) {
                $sale = $this->movements($item)->firstWhere('type', self::SALE);

                if ($sale === null || ! $this->isOut($item)) {
                    continue;
                }

                $target = $sale->product_variant_id !== null
                    ? ProductVariant::withoutGlobalScopes()->lockForUpdate()->find($sale->product_variant_id)
                    : Product::withoutGlobalScopes()->lockForUpdate()->find($sale->product_id);

                $returned = -$sale->quantity_change;

                if ($target === null || $target->inventory_quantity === null) {
                    // Nothing left to put it back on; still close the sale
                    // so a later scan takes the stock again.
                    $this->record($order, $item, null, self::SALE_REVERSAL, $returned, $sale);

                    continue;
                }

                $before = (int) $target->inventory_quantity;
                $target->update(['inventory_quantity' => $before + $returned]);

                $this->record($order, $item, $target, self::SALE_REVERSAL, $returned);
                $changes[] = $this->change($item, $item->quantity, $returned, $before);
            }

            return $changes;
        });
    }

    /** @return Collection<int, OrderItem> */
    private function items(Order $order): Collection
    {
        return OrderItem::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->with(['product' => fn ($query) => $query->withoutGlobalScopes(), 'variant' => fn ($query) => $query->withoutGlobalScopes()->with('optionValues')])
            ->get();
    }

    /**
     * This line's sale movements, newest first.
     *
     * @return Collection<int, StockMovement>
     */
    private function movements(OrderItem $item): Collection
    {
        return StockMovement::withoutGlobalScopes()
            ->where('order_item_id', $item->id)
            ->whereIn('type', [self::SALE, self::SALE_REVERSAL])
            ->orderByDesc('id')
            ->get();
    }

    /** Whether this line's stock is currently out (a sale not yet reversed). */
    private function isOut(OrderItem $item): bool
    {
        return $this->movements($item)->first()?->type === self::SALE;
    }

    /**
     * The row whose stock this line moves, locked for the update, or null
     * when there is nothing tracked to move.
     */
    private function lockedTarget(OrderItem $item): ProductVariant|Product|null
    {
        if ($item->product_variant_id !== null) {
            $variant = ProductVariant::withoutGlobalScopes()->lockForUpdate()->find($item->product_variant_id);

            return $variant?->inventory_quantity === null ? null : $variant;
        }

        if ($item->product_id === null) {
            return null;
        }

        $product = Product::withoutGlobalScopes()->lockForUpdate()->find($item->product_id);

        // A product with variants keeps its stock on them; without knowing
        // which one was ordered there is nothing safe to take.
        if ($product === null || $product->inventory_quantity === null
            || ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->exists()) {
            return null;
        }

        return $product;
    }

    private function record(Order $order, OrderItem $item, ProductVariant|Product|null $target, string $type, int $change, ?StockMovement $from = null): void
    {
        StockMovement::create([
            'business_id' => $order->business_id,
            'product_id' => $target instanceof ProductVariant ? $target->product_id : ($target?->id ?? $from?->product_id),
            'product_variant_id' => $target instanceof ProductVariant ? $target->id : $from?->product_variant_id,
            'order_item_id' => $item->id,
            'type' => $type,
            'quantity_change' => $change,
            'note' => ($type === self::SALE ? 'Scanned out' : 'Scan undone').' · '.($order->reference ?? '#'.$order->id),
        ]);
    }

    /**
     * @return array{product: string, variant: string|null, sku: string|null, ordered: int, change: int, before: int, after: int}
     */
    private function change(OrderItem $item, int $ordered, int $change, int $before): array
    {
        $variant = $item->variant?->optionValues->pluck('value')->implode(' / ');

        return [
            'product' => $item->product?->name ?? $item->product_name_snapshot,
            'variant' => $variant !== '' ? $variant : null,
            'sku' => $item->variant?->sku ?? $item->sku_snapshot,
            'ordered' => $ordered,
            'change' => $change,
            'before' => $before,
            'after' => $before + $change,
        ];
    }
}
