<?php

namespace App\Services\Operations\Orders;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Builds the {ref, qnty} product lines sent to a delivery courier when a
 * parcel is created for an order.
 *
 * ref priority: the ordered variant's own sku, then the parent product's
 * sku, then the item's name snapshot as a last resort. A bare sku doesn't
 * tell the courier which variant to hand over, so whenever a sku is used
 * the variant's option values (e.g. "Red / M") are appended to it — the
 * name-snapshot fallback already reads as a full description on its own,
 * so nothing is appended there.
 */
class ParcelProductsBuilder
{
    /**
     * @return array<int, array{ref: string, qnty: int}>
     */
    public function build(Order $order): array
    {
        $items = $order->items()->with(['product', 'variant.optionValues'])->get();

        return $items->map(fn (OrderItem $item) => [
            'ref' => $this->resolveRef($item),
            'qnty' => $item->quantity,
        ])->all();
    }

    private function resolveRef(OrderItem $item): string
    {
        $variant = $item->variant;
        $sku = $variant?->sku ?: $item->product?->sku;

        if (blank($sku)) {
            return $item->product_name_snapshot;
        }

        $options = $variant?->optionValues->pluck('value')->filter()->implode(' / ');

        return filled($options) ? "{$sku} ({$options})" : $sku;
    }
}
