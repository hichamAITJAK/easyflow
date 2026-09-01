<?php

namespace App\DTOs\YouCan;

/**
 * Represents a single line item inside a YouCan order (an entry of the
 * order's `variants` array, which nests the ordered variant and its
 * parent product).
 */
readonly class YouCanLineItemDTO
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
        public ?float $compareAtPrice,
        public ?string $imageUrl,
    ) {}

    /**
     * @param  array{
     *     id?: string|int,
     *     title?: ?string,
     *     sku?: ?string,
     *     quantity?: int,
     *     price?: float|string,
     *     variant?: array{
     *         id?: string|int,
     *         sku?: ?string,
     *         price?: float|string,
     *         compare_at_price?: float|string,
     *         variations?: mixed,
     *         image?: array{url?: ?string},
     *         product?: array{
     *             id?: string|int,
     *             name?: ?string,
     *             thumbnail?: ?string,
     *             images?: array<int, array{url?: ?string}>,
     *         },
     *     },
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $variant = $data['variant'] ?? [];
        $product = $variant['product'] ?? [];

        $variantTitle = null;
        if (! empty($variant['variations']) && is_array($variant['variations'])) {
            $variantTitle = implode(' / ', array_map('strval', $variant['variations']));
        }

        $imageUrl = $variant['image']['url']
            ?? ($product['images'][0]['url'] ?? null)
            ?? $product['thumbnail']
            ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            productId: isset($product['id']) ? (string) $product['id'] : null,
            variantId: isset($variant['id']) ? (string) $variant['id'] : null,
            title: (string) ($product['name'] ?? $data['title'] ?? ''),
            variantTitle: $variantTitle,
            sku: $variant['sku'] ?? $data['sku'] ?? null,
            quantity: (int) ($data['quantity'] ?? 1),
            price: (float) ($data['price'] ?? $variant['price'] ?? 0),
            compareAtPrice: isset($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : null,
            imageUrl: $imageUrl,
        );
    }

    public function subtotal(): float
    {
        return $this->price * $this->quantity;
    }

    /**
     * @return array{
     *     id: string,
     *     product_id: ?string,
     *     variant_id: ?string,
     *     title: string,
     *     variant_title: ?string,
     *     sku: ?string,
     *     quantity: int,
     *     price: float,
     *     compare_at_price: ?float,
     *     image_url: ?string,
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
            'compare_at_price' => $this->compareAtPrice,
            'image_url' => $this->imageUrl,
        ];
    }
}
