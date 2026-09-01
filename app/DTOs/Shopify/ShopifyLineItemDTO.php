<?php

namespace App\DTOs\Shopify;

/**
 * Represents a single line item in a Shopify order.
 */
readonly class ShopifyLineItemDTO
{
    public function __construct(
        public string $id,
        public ?string $productId,
        public ?string $variantId,
        public string $title,
        public ?string $variantTitle,
        public ?string $sku,
        public int $quantity,
        public float $price,
    ) {}

    /**
     * @param array{
     *     id?: string|int|null,
     *     product?: array{id?: string|null}|null,
     *     variant?: array{id?: string|null}|null,
     *     title?: string|null,
     *     variantTitle?: string|null,
     *     sku?: string|null,
     *     quantity?: int|string|null,
     *     originalUnitPriceSet?: array{shopMoney?: array{amount?: float|string|null}|null}|null,
     *     price?: float|string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            productId: $data['product']['id'] ?? null,
            variantId: $data['variant']['id'] ?? null,
            title: (string) ($data['title'] ?? ''),
            variantTitle: $data['variantTitle'] ?? null,
            sku: $data['sku'] ?? null,
            quantity: (int) ($data['quantity'] ?? 1),
            price: (float) ($data['originalUnitPriceSet']['shopMoney']['amount'] ?? ($data['price'] ?? 0)),
        );
    }

    public function subtotal(): float
    {
        return $this->price * $this->quantity;
    }

    /**
     * @return array{
     *     id: string,
     *     product_id: string|null,
     *     variant_id: string|null,
     *     title: string,
     *     variant_title: string|null,
     *     sku: string|null,
     *     quantity: int,
     *     price: float,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'title' => $this->title,
            'variant_title' => $this->variantTitle,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'price' => $this->price,
        ];
    }
}
