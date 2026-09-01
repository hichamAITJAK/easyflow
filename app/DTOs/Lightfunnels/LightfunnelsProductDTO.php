<?php

namespace App\DTOs\Lightfunnels;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a Lightfunnels Product.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class LightfunnelsProductDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public float $price,
        public ?string $sku,
        public ?string $imageUrl,
        public ?int $inventoryQuantity,
        /** @var LightfunnelsProductVariantDTO[] */
        public array $variants,
        /** @var array<int, string> */
        public array $tags,
        /** @var string[] Lightfunnels store ids this product is attached to. */
        public array $storeIds,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param  array{node?: array<string, mixed>, product?: array<string, mixed>, id?: mixed, title?: mixed, description?: ?string, price?: mixed, sku?: ?string, thumbnail?: array{path?: ?string}, images?: array<int, array{path?: ?string}>, inventory_quantity?: mixed, variants?: array<int, array<string, mixed>>, tags?: array<int, array{title?: mixed}>, stores?: array<int, array{id?: mixed}>, created_at?: ?string, updated_at?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        $product = $data['node'] ?? $data['product'] ?? $data;

        $variants = array_map(
            fn (array $variant) => LightfunnelsProductVariantDTO::fromArray($variant),
            $product['variants'] ?? []
        );

        $imageUrl = $product['thumbnail']['path']
            ?? ($product['images'][0]['path'] ?? null);

        $tags = array_map(
            fn (array $tag) => (string) ($tag['title'] ?? ''),
            $product['tags'] ?? []
        );

        $storeIds = array_map(
            fn (array $store) => (string) ($store['id'] ?? ''),
            $product['stores'] ?? []
        );

        return new self(
            id: (string) ($product['id'] ?? ''),
            title: (string) ($product['title'] ?? ''),
            description: $product['description'] ?? null,
            price: (float) ($product['price'] ?? 0),
            sku: $product['sku'] ?? null,
            imageUrl: $imageUrl,
            inventoryQuantity: isset($product['inventory_quantity']) ? (int) $product['inventory_quantity'] : null,
            variants: $variants,
            tags: $tags,
            storeIds: $storeIds,
            createdAt: $product['created_at'] ?? null,
            updatedAt: $product['updated_at'] ?? null,
        );
    }

    /**
     * @param  array{products?: array{edges?: array<int, array{node?: array<string, mixed>}>}}  $data
     * @return LightfunnelsProductDTO[]
     */
    public static function fromList(array $data): array
    {
        $items = [];
        foreach ($data['products']['edges'] ?? [] as $edge) {
            if (isset($edge['node'])) {
                $items[] = $edge['node'];
            }
        }

        return array_map(
            fn (array $product) => self::fromArray($product),
            $items
        );
    }

    /**
     * @return array{id: string, title: string, description: ?string, price: float, sku: ?string, image_url: ?string, inventory_quantity: ?int, variants: array<int, array<string, mixed>>, tags: array<int, string>, store_ids: array<int, string>, status: string, created_at: ?string, updated_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'sku' => $this->sku,
            'image_url' => $this->imageUrl,
            'inventory_quantity' => $this->inventoryQuantity,
            'variants' => array_map(fn ($v) => $v->toArray(), $this->variants),
            'tags' => $this->tags,
            'store_ids' => $this->storeIds,
            // Lightfunnels has no product-level active/archived flag exposed
            // via the API, so every synced product is treated as active.
            'status' => 'active',
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
