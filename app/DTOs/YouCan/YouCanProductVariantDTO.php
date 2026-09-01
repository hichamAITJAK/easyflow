<?php

namespace App\DTOs\YouCan;

/**
 * Represents a single product variant in YouCan.
 */
readonly class YouCanProductVariantDTO
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
     * @param  array{
     *     id?: string|int,
     *     title?: ?string,
     *     name?: ?string,
     *     sku?: ?string,
     *     price?: float|string,
     *     inventory?: int|string,
     *     inventory_quantity?: int|string,
     *     quantity?: int|string,
     *     available?: bool,
     *     active?: bool,
     *     variations?: array<string, mixed>,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            title: $data['title'] ?? $data['name'] ?? null,
            sku: $data['sku'] ?? null,
            price: (float) ($data['price'] ?? 0),
            inventoryQuantity: isset($data['inventory']) ? (int) $data['inventory'] : (isset($data['inventory_quantity']) ? (int) $data['inventory_quantity'] : (isset($data['quantity']) ? (int) $data['quantity'] : null)),
            available: (bool) ($data['available'] ?? ($data['active'] ?? true)),
            optionValues: is_array($data['variations'] ?? null)
                ? array_map('strval', $data['variations'])
                : [],
        );
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
