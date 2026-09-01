<?php

namespace App\DTOs\Storeep;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a product in Storeep (from listProducts()).
 *
 * Storeep's product shape differs from every other platform we integrate:
 * it carries BOTH a `variants[]` array (the option axes — Size → S/M/L,
 * Color → Red/Blue) AND an `options[]` array (the actually-purchasable
 * rows, each with its own sku/barcode/weight and per-market pricing).
 * Those names are the inverse of the industry convention, where "options"
 * are the axes and "variants" are the purchasable combinations.
 *
 * This DTO normalizes to the convention the rest of the codebase uses:
 * `variants` here means the purchasable rows (built from Storeep's
 * `options[]`), and the axis definitions are exposed separately as
 * `optionAxes` for display.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class StoreepProductDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?string $status,
        public ?string $imageUrl,
        public ?string $publicUrl,
        /** @var StoreepProductVariantDTO[] */
        public array $variants,
        /** @var array<int, array{name: ?string, type: ?string, values: array<int, string>}> */
        public array $optionAxes,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param  array{
     *     product?: array<string, mixed>,
     *     id?: string|int,
     *     name?: ?string,
     *     title?: ?string,
     *     media?: array<int, array{type?: ?string, url?: ?string, thumbnail?: ?string}>,
     *     variants?: array<int, array{type?: ?string, name?: ?string, options?: array<int, array{name?: ?string, src?: ?string}>}>,
     *     options?: array<int, array<string, mixed>>,
     *     is_published?: bool,
     *     created_at?: ?string,
     *     updated_at?: ?string,
     * }  $data
     * @param  string|null  $preferredMarket  Market whose pricing to use, when the
     *                                        product is priced in several markets.
     */
    public static function fromArray(array $data, ?string $preferredMarket = null): self
    {
        $product = $data['product'] ?? $data;

        $variants = array_map(
            fn (array $option) => StoreepProductVariantDTO::fromArray($option, $preferredMarket),
            $product['options'] ?? []
        );

        return new self(
            id: (string) ($product['id'] ?? ''),
            title: (string) ($product['name'] ?? $product['title'] ?? ''),
            // Storeep's product payload carries no description field at all.
            description: $product['description'] ?? null,
            // No `status` field either — `is_published` (storefront-visible
            // or not) is the closest analog. Mapped to an explicit
            // 'active'/'inactive' string rather than passed through as a raw
            // bool, since PHP's bool->string coercion ("1"/"") would silently
            // fail the strict in_array() check that reads it downstream in
            // ProductSyncService.
            status: isset($product['is_published'])
                ? ($product['is_published'] ? 'active' : 'inactive')
                : null,
            imageUrl: self::firstImageUrl($product['media'] ?? []),
            publicUrl: $product['public_url'] ?? $product['url'] ?? null,
            variants: $variants,
            optionAxes: self::optionAxesFrom($product['variants'] ?? []),
            createdAt: $product['created_at'] ?? null,
            updatedAt: $product['updated_at'] ?? null,
        );
    }

    /**
     * Pick the product's display image out of its mixed media list.
     *
     * `media` interleaves images and videos; a video entry carries a
     * `thumbnail` and a YouTube-style `id` but no `url`, so picking the
     * first entry blindly can yield an entry with no usable image. Images
     * are preferred, with a video thumbnail as the fallback.
     *
     * @param  array<int, array{type?: ?string, url?: ?string, thumbnail?: ?string}>  $media
     */
    private static function firstImageUrl(array $media): ?string
    {
        foreach ($media as $item) {
            if (($item['type'] ?? null) === 'image' && ! empty($item['url'])) {
                return $item['url'];
            }
        }

        foreach ($media as $item) {
            if (! empty($item['thumbnail'])) {
                return $item['thumbnail'];
            }
        }

        return null;
    }

    /**
     * Flatten Storeep's option-axis definitions (its `variants[]`) into a
     * simple name + values shape, dropping the per-value swatch images.
     *
     * @param  array<int, array{type?: ?string, name?: ?string, options?: array<int, array{name?: ?string}>}>  $axes
     * @return array<int, array{name: ?string, type: ?string, values: array<int, string>}>
     */
    private static function optionAxesFrom(array $axes): array
    {
        return array_values(array_map(
            fn (array $axis) => [
                'name' => $axis['name'] ?? null,
                'type' => $axis['type'] ?? null,
                'values' => array_values(array_filter(array_map(
                    fn (array $value) => (string) ($value['name'] ?? ''),
                    $axis['options'] ?? []
                ), fn (string $value) => $value !== '')),
            ],
            $axes
        ));
    }

    /**
     * @param  array{data?: array<int, array<string, mixed>>, products?: array<int, array<string, mixed>>}|array<int, array<string, mixed>>  $data
     * @return StoreepProductDTO[]
     */
    public static function fromList(array $data, ?string $preferredMarket = null): array
    {
        $items = $data['data'] ?? $data['products'] ?? $data;

        return array_map(
            fn (array $product) => self::fromArray($product, $preferredMarket),
            $items
        );
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     description: ?string,
     *     status: ?string,
     *     image_url: ?string,
     *     public_url: ?string,
     *     variants: array<int, array<string, mixed>>,
     *     option_axes: array<int, array{name: ?string, type: ?string, values: array<int, string>}>,
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
            'image_url' => $this->imageUrl,
            'public_url' => $this->publicUrl,
            'variants' => array_map(fn ($v) => $v->toArray(), $this->variants),
            'option_axes' => $this->optionAxes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
