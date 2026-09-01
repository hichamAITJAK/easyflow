<?php

namespace App\DTOs\YouCan;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a product in YouCan (from listProducts() / getProduct()).
 *
 * @implements Arrayable<string, mixed>
 */
readonly class YouCanProductDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?string $descriptionHtml,
        public ?string $status,
        public ?string $imageUrl,
        public ?string $publicUrl,
        /** @var YouCanProductVariantDTO[] */
        public array $variants,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param  array{
     *     product?: array<string, mixed>,
     *     id?: string|int,
     *     title?: ?string,
     *     name?: ?string,
     *     description_html?: ?string,
     *     description?: ?string,
     *     body?: ?string,
     *     status?: ?string,
     *     visibility?: bool,
     *     thumbnail?: ?string,
     *     image_url?: ?string,
     *     image?: ?string,
     *     images?: array<int, array{url?: ?string}>,
     *     public_url?: ?string,
     *     variants?: array<int, array<string, mixed>>,
     *     created_at?: ?string,
     *     updated_at?: ?string,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $product = $data['product'] ?? $data;

        $variants = array_map(
            fn (array $v) => YouCanProductVariantDTO::fromArray($v),
            $product['variants'] ?? []
        );

        return new self(
            id: (string) ($product['id'] ?? ''),
            title: (string) ($product['title'] ?? $product['name'] ?? ''),
            description: $product['description_html'] ?? null,
            descriptionHtml: $product['description'] ?? $product['body'] ?? null,
            // YouCan's /products response has no `status` field — the
            // closest analog is `visibility` (storefront-visible or not).
            // Mapped to an explicit 'active'/'inactive' string rather than
            // passed through as a raw bool, since this property is typed
            // ?string and PHP's bool->string coercion ("1"/"") would
            // silently fail the strict in_array() check that reads it
            // downstream in ProductSyncService.
            status: $product['status'] ?? (isset($product['visibility'])
                ? ($product['visibility'] ? 'active' : 'inactive')
                : null),
            imageUrl: $product['thumbnail'] ?? $product['image_url'] ?? $product['image'] ?? ($product['images'][0]['url'] ?? null),
            publicUrl: $product['public_url'] ?? null,
            variants: $variants,
            createdAt: $product['created_at'] ?? null,
            updatedAt: $product['updated_at'] ?? null,
        );
    }

    /**
     * @param  array{products?: array<int, array<string, mixed>>, data?: array<int, array<string, mixed>>}|array<int, array<string, mixed>>  $data
     * @return YouCanProductDTO[]
     */
    public static function fromList(array $data): array
    {
        $items = $data['products'] ?? $data['data'] ?? $data;

        return array_map(
            fn (array $product) => self::fromArray($product),
            $items
        );
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     description: ?string,
     *     description_html: ?string,
     *     status: ?string,
     *     image_url: ?string,
     *     public_url: ?string,
     *     variants: array<int, array<string, mixed>>,
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
            'description_html' => $this->descriptionHtml,
            'status' => $this->status,
            'image_url' => $this->imageUrl,
            'public_url' => $this->publicUrl,
            'variants' => array_map(fn ($v) => $v->toArray(), $this->variants),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
