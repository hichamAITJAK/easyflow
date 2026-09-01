<?php

namespace App\DTOs\WooCommerce;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a WooCommerce product.
 *
 * WooCommerce products come in four types (simple, variable, grouped,
 * external). Only the first two are sellable rows in the sense the rest of
 * this codebase means:
 *
 *  - **simple** — the product IS the purchasable row. One variant is
 *    synthesized from the product itself, so downstream code never has to
 *    special-case "a product with no variants".
 *  - **variable** — the purchasable rows live on a separate endpoint
 *    (/products/{id}/variations) and are passed in here.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class WooCommerceProductDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?string $status,
        public ?string $type,
        public ?string $imageUrl,
        public ?string $publicUrl,
        /** @var WooCommerceProductVariantDTO[] */
        public array $variants,
        /** @var array<int, array{name: ?string, values: array<int, string>}> */
        public array $optionAxes,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param  array{
     *     id?: string|int,
     *     name?: ?string,
     *     description?: ?string,
     *     short_description?: ?string,
     *     status?: ?string,
     *     type?: ?string,
     *     permalink?: ?string,
     *     images?: array<int, array{src?: ?string}>,
     *     attributes?: array<int, array{name?: ?string, options?: array<int, string>, variation?: bool}>,
     *     date_created_gmt?: ?string,
     *     date_created?: ?string,
     *     date_modified_gmt?: ?string,
     *     date_modified?: ?string,
     * }  $product  A WooCommerce product, exactly as the REST API represents
     *               it — there is no envelope to unwrap.
     * @param  array<int, array<string, mixed>>  $variations  Rows from
     *                                                        /products/{id}/variations, for a variable product.
     */
    public static function fromArray(array $product, array $variations = []): self
    {
        $type = $product['type'] ?? null;

        // A variable product's sellable rows come from the variations
        // endpoint. Everything else (simple, and the grouped/external types
        // that carry no stock of their own) is its own single row.
        $variants = $variations !== []
            ? WooCommerceProductVariantDTO::fromList($variations)
            : [WooCommerceProductVariantDTO::fromSimpleProduct($product)];

        return new self(
            id: (string) ($product['id'] ?? ''),
            title: (string) ($product['name'] ?? ''),
            // `description` is the full HTML body; short_description is the
            // excerpt. Preferring the full one matches the other platforms.
            description: self::nullIfBlank($product['description'] ?? $product['short_description'] ?? null),
            // WooCommerce statuses are WordPress post statuses (publish,
            // draft, pending, private). Only `publish` is live, and it is
            // normalized to the 'active'/'inactive' vocabulary
            // ProductSyncService checks against.
            status: isset($product['status'])
                ? ($product['status'] === 'publish' ? 'active' : 'inactive')
                : null,
            type: $type,
            imageUrl: self::nullIfBlank($product['images'][0]['src'] ?? null),
            publicUrl: self::nullIfBlank($product['permalink'] ?? null),
            variants: $variants,
            optionAxes: self::optionAxesFrom($product['attributes'] ?? []),
            createdAt: $product['date_created_gmt'] ?? $product['date_created'] ?? null,
            updatedAt: $product['date_modified_gmt'] ?? $product['date_modified'] ?? null,
        );
    }

    /**
     * Flatten the product's variation-forming attributes into name + values.
     *
     * A WooCommerce attribute with `variation: false` is a spec/filter
     * attribute (Material: Cotton) rather than an option axis the customer
     * picks from, so only the variation-forming ones are exposed here.
     *
     * @param  array<int, array{name?: ?string, options?: array<int, string>, variation?: bool}>  $attributes
     * @return array<int, array{name: ?string, values: array<int, string>}>
     */
    private static function optionAxesFrom(array $attributes): array
    {
        $axes = [];

        foreach ($attributes as $attribute) {
            if (! ($attribute['variation'] ?? false)) {
                continue;
            }

            $axes[] = [
                'name' => self::nullIfBlank($attribute['name'] ?? null),
                'values' => array_values(array_filter(array_map(
                    fn ($value) => trim((string) $value),
                    $attribute['options'] ?? []
                ), fn (string $value) => $value !== '')),
            ];
        }

        return $axes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return WooCommerceProductDTO[]
     */
    public static function fromList(array $products): array
    {
        return array_map(fn (array $product) => self::fromArray($product), $products);
    }

    /**
     * Whether this product's purchasable rows live on the variations
     * endpoint rather than on the product itself.
     */
    public function isVariable(): bool
    {
        return $this->type === 'variable';
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     description: ?string,
     *     status: ?string,
     *     type: ?string,
     *     image_url: ?string,
     *     public_url: ?string,
     *     variants: array<int, array<string, mixed>>,
     *     option_axes: array<int, array{name: ?string, values: array<int, string>}>,
     *     created_at: ?string,
     *     updated_at: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'type' => $this->type,
            'image_url' => $this->imageUrl,
            'public_url' => $this->publicUrl,
            'variants' => array_map(fn ($v) => $v->toArray(), $this->variants),
            'option_axes' => $this->optionAxes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
