<?php

namespace App\DTOs\Storeep;

/**
 * Represents a single line item inside a Storeep order (an entry of the
 * order's `items` array).
 *
 * Storeep's order items carry NO product id and NO variant id — only a
 * `sku`, a `barcode`, and the ordered variant axis values. So `product_id`
 * is emitted as null and OrderSyncService's sku fallback is what links a
 * line item to a product variant (see its matchVariant()). That fallback
 * only matches variants, not products, which means a Storeep order line
 * lands with a null product_id — the same null-safe path Lightfunnels
 * already relies on.
 */
readonly class StoreepLineItemDTO
{
    public function __construct(
        public ?string $sku,
        public ?string $barcode,
        public string $title,
        public ?string $variantTitle,
        public int $quantity,
        public float $price,
        public ?float $weight,
    ) {}

    /**
     * @param  array{
     *     name?: ?string,
     *     price?: float|string|null,
     *     quantity?: int|string|null,
     *     sku?: ?string,
     *     barcode?: ?string,
     *     weight?: float|string|null,
     *     variants?: array<int, array{type?: ?string, name?: ?string, options?: array<int, array{name?: ?string}>}>,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sku: $data['sku'] ?? null,
            barcode: $data['barcode'] ?? null,
            title: (string) ($data['name'] ?? ''),
            variantTitle: self::variantTitleFrom($data['variants'] ?? []),
            quantity: (int) ($data['quantity'] ?? 1),
            price: (float) ($data['price'] ?? 0),
            weight: isset($data['weight']) ? (float) $data['weight'] : null,
        );
    }

    /**
     * Build a human-readable variant label ("M / Red") out of the ordered
     * item's option axes.
     *
     * OrderSyncService folds this into the line item's product name
     * snapshot, which is what keeps two different variants of the same
     * product from collapsing onto one order_items row.
     *
     * @param  array<int, array{name?: ?string, options?: array<int, array{name?: ?string}>}>  $variants
     */
    private static function variantTitleFrom(array $variants): ?string
    {
        $values = [];

        foreach ($variants as $axis) {
            foreach ($axis['options'] ?? [] as $option) {
                if (! empty($option['name'])) {
                    $values[] = (string) $option['name'];
                }
            }
        }

        return $values === [] ? null : implode(' / ', $values);
    }

    public function subtotal(): float
    {
        return $this->price * $this->quantity;
    }

    /**
     * @return array{
     *     id: ?string,
     *     product_id: null,
     *     variant_id: null,
     *     title: string,
     *     variant_title: ?string,
     *     sku: ?string,
     *     barcode: ?string,
     *     quantity: int,
     *     price: float,
     *     weight: ?float,
     * }
     */
    public function toArray(): array
    {
        return [
            // Storeep gives line items no identifier of their own; the sku
            // is the only stable handle, and it is what variant matching
            // keys off downstream.
            'id' => $this->sku,
            'product_id' => null,
            'variant_id' => null,
            'title' => $this->title,
            'variant_title' => $this->variantTitle,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'weight' => $this->weight,
        ];
    }
}
