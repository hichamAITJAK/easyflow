<?php

namespace App\DTOs\WooCommerce;

/**
 * Represents a purchasable row of a WooCommerce product.
 *
 * WooCommerce splits this across two shapes, and both land here:
 *
 *  - A **simple** product is itself the purchasable row — it carries its own
 *    sku/price/stock and has no variations. One of these is synthesized from
 *    the product so that every product has at least one variant downstream,
 *    matching how the other platforms' catalogs are modeled.
 *  - A **variable** product's rows come from /products/{id}/variations, each
 *    with its own sku, price, stock and chosen attribute values.
 */
readonly class WooCommerceProductVariantDTO
{
    public function __construct(
        public string $id,
        public ?string $title,
        public ?string $sku,
        public float $price,
        public ?int $inventoryQuantity,
        public bool $available,
        /** @var array<string, string> option name => value, e.g. ['Size' => 'M'] */
        public array $optionValues,
        public ?float $regularPrice,
        public ?float $salePrice,
        public ?float $weight,
        public ?string $imageUrl,
    ) {}

    /**
     * Build a variant from a /products/{id}/variations row.
     *
     * @param  array{
     *     id?: string|int,
     *     sku?: ?string,
     *     price?: float|string|null,
     *     regular_price?: float|string|null,
     *     sale_price?: float|string|null,
     *     stock_quantity?: int|string|null,
     *     manage_stock?: bool,
     *     stock_status?: ?string,
     *     weight?: float|string|null,
     *     image?: array{src?: ?string},
     *     attributes?: array<int, array{name?: ?string, option?: ?string}>,
     * }  $data
     */
    public static function fromVariation(array $data): self
    {
        $optionValues = [];

        foreach ($data['attributes'] ?? [] as $attribute) {
            $name = trim((string) ($attribute['name'] ?? ''));
            $option = trim((string) ($attribute['option'] ?? ''));

            if ($name !== '' && $option !== '') {
                $optionValues[$name] = $option;
            }
        }

        $title = $optionValues === [] ? null : implode(' / ', array_values($optionValues));

        return new self(
            id: (string) ($data['id'] ?? ''),
            title: $title,
            sku: self::nullIfBlank($data['sku'] ?? null),
            price: (float) ($data['price'] ?? 0),
            inventoryQuantity: self::stockQuantity($data),
            available: ($data['stock_status'] ?? 'instock') === 'instock',
            optionValues: $optionValues,
            regularPrice: self::floatOrNull($data['regular_price'] ?? null),
            salePrice: self::floatOrNull($data['sale_price'] ?? null),
            weight: self::floatOrNull($data['weight'] ?? null),
            imageUrl: self::nullIfBlank($data['image']['src'] ?? null),
        );
    }

    /**
     * Synthesize the single variant of a simple product, so that a simple
     * and a variable product present the same shape downstream.
     *
     * @param  array<string, mixed>  $product
     */
    public static function fromSimpleProduct(array $product): self
    {
        return new self(
            id: (string) ($product['id'] ?? ''),
            // A simple product has no option axes, so it has no variant
            // label of its own — the product's name is already the title.
            title: null,
            sku: self::nullIfBlank($product['sku'] ?? null),
            price: (float) ($product['price'] ?? 0),
            inventoryQuantity: self::stockQuantity($product),
            available: ($product['stock_status'] ?? 'instock') === 'instock',
            optionValues: [],
            regularPrice: self::floatOrNull($product['regular_price'] ?? null),
            salePrice: self::floatOrNull($product['sale_price'] ?? null),
            weight: self::floatOrNull($product['weight'] ?? null),
            imageUrl: self::nullIfBlank($product['images'][0]['src'] ?? null),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $variations
     * @return WooCommerceProductVariantDTO[]
     */
    public static function fromList(array $variations): array
    {
        return array_map(fn (array $variation) => self::fromVariation($variation), $variations);
    }

    /**
     * The stock level, or null when the store doesn't track stock for this
     * row.
     *
     * With `manage_stock` off WooCommerce reports `stock_quantity: null`,
     * which means "unlimited/untracked" — emphatically not zero, which would
     * read downstream as out of stock and is a different claim entirely.
     *
     * @param  array<string, mixed>  $data
     */
    private static function stockQuantity(array $data): ?int
    {
        $quantity = $data['stock_quantity'] ?? null;

        return $quantity === null || $quantity === '' ? null : (int) $quantity;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{
     *     id: string,
     *     title: ?string,
     *     sku: ?string,
     *     price: float,
     *     inventory_quantity: ?int,
     *     available: bool,
     *     option_values: array<string, string>,
     *     regular_price: ?float,
     *     sale_price: ?float,
     *     weight: ?float,
     *     image_url: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'sku' => $this->sku,
            'price' => $this->price,
            'inventory_quantity' => $this->inventoryQuantity,
            'available' => $this->available,
            'option_values' => $this->optionValues,
            'regular_price' => $this->regularPrice,
            'sale_price' => $this->salePrice,
            'weight' => $this->weight,
            'image_url' => $this->imageUrl,
        ];
    }
}
