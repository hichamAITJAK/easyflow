<?php

namespace App\DTOs\Sendit;

/**
 * Request payload to create or update a Sendit parcel ("colis"), matching
 * the `NewColisData` schema used by both POST /deliveries and
 * PUT /deliveries/{code}. Pass toArray() to
 * ParcelService::createParcel() / updateParcel().
 */
readonly class SenditNewParcelDTO
{
    public function __construct(
        public int $districtId,
        public string $name,
        public string $phone,
        public string $address,
        public float $amount,
        public ?int $pickupDistrictId = null,
        public ?string $comment = null,
        public ?string $reference = null,
        public ?bool $allowOpen = null,
        public ?bool $allowTry = null,
        public ?bool $productsFromStock = null,
        /** Format: 'CODE:QTY;CODE2:QTY', only used when productsFromStock is true. */
        public ?string $products = null,
        public ?int $packagingId = null,
        public ?bool $optionExchange = null,
        public ?string $deliveryExchangeId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'district_id' => $this->districtId,
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'amount' => $this->amount,
            'pickup_district_id' => $this->pickupDistrictId,
            'comment' => $this->comment,
            'reference' => $this->reference,
            'allow_open' => $this->allowOpen === null ? null : (int) $this->allowOpen,
            'allow_try' => $this->allowTry === null ? null : (int) $this->allowTry,
            'products_from_stock' => $this->productsFromStock === null ? null : (int) $this->productsFromStock,
            'products' => $this->products,
            'packaging_id' => $this->packagingId,
            'option_exchange' => $this->optionExchange === null ? null : (int) $this->optionExchange,
            'delivery_exchange_id' => $this->deliveryExchangeId,
        ], fn ($value) => $value !== null);
    }
}
