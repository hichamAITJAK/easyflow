<?php

namespace App\DTOs\WooCommerce;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a single line item on a WooCommerce order.
 *
 * `variation_id` is 0 (not null) on a simple product's line item, which is
 * normalized to null here so downstream variant matching doesn't try to
 * look up a variant with id "0".
 *
 * @implements Arrayable<string, mixed>
 */
readonly class WooCommerceLineItemDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public string $productId,
        public ?string $variantId,
        public string $title,
        public ?string $variantTitle,
        public ?string $sku,
        public int $quantity,
        public float $price,
        public float $total,
        public ?string $imageUrl,
    ) {}

    /**
     * @param  array{
     *     id?: string|int,
     *     product_id?: string|int,
     *     variation_id?: string|int,
     *     name?: ?string,
     *     sku?: ?string,
     *     quantity?: int|string|null,
     *     price?: float|string|null,
     *     total?: float|string|null,
     *     image?: array{src?: ?string},
     *     meta_data?: array<int, array{key?: ?string, value?: mixed, display_key?: ?string, display_value?: mixed}>,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $variationId = (string) ($data['variation_id'] ?? '');

        return new self(
            id: (string) ($data['id'] ?? ''),
            productId: (string) ($data['product_id'] ?? ''),
            // A simple product reports variation_id 0; that is "no variant",
            // not variant zero.
            variantId: ($variationId === '' || $variationId === '0') ? null : $variationId,
            title: (string) ($data['name'] ?? 'Unknown product'),
            variantTitle: self::variantTitleFrom($data['meta_data'] ?? []),
            sku: self::nullIfBlank($data['sku'] ?? null),
            quantity: (int) ($data['quantity'] ?? 0),
            // `price` is the unit price; `total` is the line total AFTER
            // discounts, which is what the order's own total is built from.
            price: (float) ($data['price'] ?? 0),
            total: (float) ($data['total'] ?? 0),
            imageUrl: self::nullIfBlank($data['image']['src'] ?? null),
        );
    }

    /**
     * Build a human-readable variant label out of the line item's meta.
     *
     * WooCommerce does not put a variant title on the line item the way
     * Shopify does — the chosen options live in `meta_data` as
     * display_key/display_value pairs ("Size: M"). Internal WooCommerce
     * meta keys are prefixed with an underscore and are skipped.
     *
     * @param  array<int, array{key?: ?string, display_key?: ?string, display_value?: mixed, value?: mixed}>  $meta
     */
    private static function variantTitleFrom(array $meta): ?string
    {
        $parts = [];

        foreach ($meta as $entry) {
            $key = (string) ($entry['display_key'] ?? $entry['key'] ?? '');

            if ($key === '' || str_starts_with($key, '_')) {
                continue;
            }

            $value = $entry['display_value'] ?? $entry['value'] ?? null;

            // Attribute values are scalars; anything else is plugin data we
            // have no business rendering into a product name.
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $parts[] = "{$key}: {$value}";
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return WooCommerceLineItemDTO[]
     */
    public static function fromList(array $items): array
    {
        return array_map(fn (array $item) => self::fromArray($item), $items);
    }

    /**
     * @return array{
     *     id: string,
     *     product_id: string,
     *     variant_id: ?string,
     *     title: string,
     *     variant_title: ?string,
     *     sku: ?string,
     *     quantity: int,
     *     price: float,
     *     total: float,
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
            'total' => $this->total,
            'image_url' => $this->imageUrl,
        ];
    }
}
