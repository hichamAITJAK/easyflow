<?php

namespace App\DTOs\Shopify;

/**
 * Represents a single variant of a Shopify product.
 */
readonly class ShopifyProductVariantDTO
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
    ) {}

    /**
     * @param array{
     *     id?: string|null,
     *     title?: string|null,
     *     sku?: string|null,
     *     price?: float|string|null,
     *     inventoryQuantity?: int|string|null,
     *     availableForSale?: bool|null,
     *     selectedOptions?: array<int, array{name?: string|null, value?: string|null}>|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $optionValues = [];
        foreach ($data['selectedOptions'] ?? [] as $option) {
            if (! empty($option['name'])) {
                $optionValues[$option['name']] = (string) ($option['value'] ?? '');
            }
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            title: $data['title'] ?? null,
            sku: $data['sku'] ?? null,
            price: (float) ($data['price'] ?? 0),
            inventoryQuantity: isset($data['inventoryQuantity']) ? (int) $data['inventoryQuantity'] : null,
            available: (bool) ($data['availableForSale'] ?? true),
            optionValues: $optionValues,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     title: string|null,
     *     sku: string|null,
     *     price: float,
     *     inventory_quantity: int|null,
     *     available: bool,
     *     option_values: array<string, string>,
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
        ];
    }
}
