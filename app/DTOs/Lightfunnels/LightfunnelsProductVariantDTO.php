<?php

namespace App\DTOs\Lightfunnels;

/**
 * Represents a single variant of a Lightfunnels product.
 */
readonly class LightfunnelsProductVariantDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public float $price,
        public ?float $compareAtPrice,
        public ?string $sku,
        public ?int $inventoryQuantity,
        /** @var array<string, string> option name => value, e.g. ['Size' => 'M'] */
        public array $optionValues,
    ) {}

    /**
     * @param  array{id?: mixed, title?: mixed, price?: mixed, compare_at_price?: mixed, sku?: ?string, inventory_quantity?: mixed, labeldOptions?: array<int, array{label?: ?string, value?: mixed}>}  $data
     */
    public static function fromArray(array $data): self
    {
        // Variant-level `options` returns bare ProductOptionValue {id, value}
        // with no label — `labeldOptions` (Lightfunnels' own field name,
        // typo included) is the one that pairs each value with its option
        // label, e.g. "Size" => "M".
        $optionValues = [];
        foreach ($data['labeldOptions'] ?? [] as $option) {
            if (! empty($option['label'])) {
                $optionValues[$option['label']] = (string) ($option['value'] ?? '');
            }
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            price: (float) ($data['price'] ?? 0),
            compareAtPrice: isset($data['compare_at_price']) ? (float) $data['compare_at_price'] : null,
            sku: $data['sku'] ?? null,
            inventoryQuantity: isset($data['inventory_quantity']) ? (int) $data['inventory_quantity'] : null,
            optionValues: $optionValues,
        );
    }

    /**
     * @return array{id: string, title: string, price: float, compare_at_price: ?float, sku: ?string, inventory_quantity: ?int, option_values: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'price' => $this->price,
            'compare_at_price' => $this->compareAtPrice,
            'sku' => $this->sku,
            'inventory_quantity' => $this->inventoryQuantity,
            'option_values' => $this->optionValues,
        ];
    }
}
