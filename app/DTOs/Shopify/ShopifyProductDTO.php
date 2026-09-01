<?php

namespace App\DTOs\Shopify;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a Shopify Product.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class ShopifyProductDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?string $descriptionHtml,
        public ?string $status,
        public ?string $imageUrl,
        public ?string $publicUrl,
        /** @var ShopifyProductVariantDTO[] */
        public array $variants,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param array{
     *     product?: array<string, mixed>|null,
     *     id?: string|null,
     *     title?: string|null,
     *     description?: string|null,
     *     descriptionHtml?: string|null,
     *     status?: string|null,
     *     featuredImage?: array{url?: string|null}|null,
     *     imageUrl?: string|null,
     *     onlineStoreUrl?: string|null,
     *     onlineStorePreviewUrl?: string|null,
     *     variants?: array{edges?: array<int, array{node?: array<string, mixed>}>}|array<int, array<string, mixed>>|null,
     *     createdAt?: string|null,
     *     updatedAt?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $product = $data['product'] ?? $data;

        $rawVariants = [];
        if (isset($product['variants']['edges'])) {
            foreach ($product['variants']['edges'] as $edge) {
                if (isset($edge['node'])) {
                    $rawVariants[] = $edge['node'];
                }
            }
        } else {
            $rawVariants = $product['variants'] ?? [];
        }

        $variants = array_map(
            fn (array $v) => ShopifyProductVariantDTO::fromArray($v),
            $rawVariants
        );

        $imageUrl = $product['featuredImage']['url'] ?? ($product['imageUrl'] ?? null);

        return new self(
            id: (string) ($product['id'] ?? ''),
            title: (string) ($product['title'] ?? ''),
            description: $product['description'] ?? null,
            descriptionHtml: $product['descriptionHtml'] ?? null,
            status: $product['status'] ?? null,
            imageUrl: $imageUrl,
            publicUrl: $product['onlineStoreUrl'] ?? $product['onlineStorePreviewUrl'] ?? null,
            variants: $variants,
            createdAt: $product['createdAt'] ?? null,
            updatedAt: $product['updatedAt'] ?? null,
        );
    }

    /**
     * @param array{
     *     products?: array{edges?: array<int, array{node?: array<string, mixed>}>}|array<int, array<string, mixed>>|null,
     *     data?: array<int, array<string, mixed>>|null,
     * } $data
     * @return ShopifyProductDTO[]
     */
    public static function fromList(array $data): array
    {
        $items = [];
        if (isset($data['products']['edges'])) {
            foreach ($data['products']['edges'] as $edge) {
                if (isset($edge['node'])) {
                    $items[] = $edge['node'];
                }
            }
        } else {
            $items = $data['products'] ?? $data['data'] ?? $data;
        }

        return array_map(
            fn (array $product) => self::fromArray($product),
            $items
        );
    }

    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     description: string|null,
     *     description_html: string|null,
     *     status: string|null,
     *     image_url: string|null,
     *     public_url: string|null,
     *     variants: array<int, array<string, mixed>>,
     *     created_at: string|null,
     *     updated_at: string|null,
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
