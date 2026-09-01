<?php

namespace App\DTOs\Storeep;

/**
 * Represents a single purchasable variant of a Storeep product.
 *
 * Storeep splits what other platforms call a "variant" across two fields:
 * `options[]` carries the sellable rows (sku, barcode, weight, per-market
 * pricing), while `variants[]` carries the *option axes* (Size → S/M/L,
 * Color → Red/Blue) without linking a specific combination to a specific
 * option row. So one of these is built per entry of `options[]`, and the
 * axis names are only used to label the product's option set.
 */
readonly class StoreepProductVariantDTO
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
        public ?string $barcode,
        public ?float $weight,
        public ?string $currency,
        public ?float $discountedPrice,
    ) {}

    /**
     * @param  array{
     *     id?: string|int,
     *     sku?: ?string,
     *     barcode?: ?string,
     *     weight?: float|string|null,
     *     markets?: array<int, array{
     *         market?: ?string,
     *         currency?: ?string,
     *         price?: float|string|null,
     *         discounted_price?: float|string|null,
     *     }>,
     * }  $data
     * @param  string|null  $preferredMarket  Pick this market's pricing row when the
     *                                        option is priced in several markets.
     */
    public static function fromArray(array $data, ?string $preferredMarket = null): self
    {
        $market = self::resolveMarket($data['markets'] ?? [], $preferredMarket);

        // Storeep prices per market, and a discounted price (when set) is
        // what the customer actually pays — so that is what an order's line
        // item will be priced at, and what should be shown as this variant's
        // price. `price` stays available as compare-at context.
        $price = $market['discounted_price'] ?? $market['price'] ?? 0;

        return new self(
            id: (string) ($data['id'] ?? ''),
            title: $data['sku'] ?? null,
            sku: $data['sku'] ?? null,
            price: (float) $price,
            // Storeep's products endpoint exposes no stock level at all —
            // null (unknown), never 0, which would read downstream as
            // "out of stock" and is a different claim entirely.
            inventoryQuantity: null,
            available: true,
            optionValues: [],
            barcode: $data['barcode'] ?? null,
            weight: isset($data['weight']) ? (float) $data['weight'] : null,
            currency: $market['currency'] ?? null,
            discountedPrice: isset($market['discounted_price']) ? (float) $market['discounted_price'] : null,
        );
    }

    /**
     * Pick the pricing row to use out of an option's per-market list.
     *
     * Prefers the store's own market when we know it, so a multi-market
     * catalog syncs at the prices that store actually sells at; otherwise
     * falls back to the first row rather than dropping pricing entirely.
     *
     * @param  array<int, array<string, mixed>>  $markets
     * @return array<string, mixed>
     */
    private static function resolveMarket(array $markets, ?string $preferredMarket): array
    {
        if ($markets === []) {
            return [];
        }

        if ($preferredMarket !== null) {
            foreach ($markets as $market) {
                if (($market['market'] ?? null) === $preferredMarket) {
                    return $market;
                }
            }
        }

        return $markets[0];
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
     *     barcode: ?string,
     *     weight: ?float,
     *     currency: ?string,
     *     discounted_price: ?float,
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
            'barcode' => $this->barcode,
            'weight' => $this->weight,
            'currency' => $this->currency,
            'discounted_price' => $this->discountedPrice,
        ];
    }
}
