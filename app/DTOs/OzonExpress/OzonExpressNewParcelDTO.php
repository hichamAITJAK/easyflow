<?php

namespace App\DTOs\OzonExpress;

/**
 * Request payload to create an OzonExpress parcel, matching the form-data
 * fields expected by POST /customers/{id}/{key}/add-parcel. Pass toArray()
 * to OzonExpressClient::createParcel().
 */
readonly class OzonExpressNewParcelDTO
{
    public function __construct(
        public string $receiver,
        public string $phone,
        /** The destination city's external_courrier_id, not its name — OzonExpress's parcel-city expects the city id it assigns, same as Sendit's district_id. */
        public int $cityId,
        public string $address,
        public float $price,
        public bool $stock,
        public ?string $trackingNumber = null,
        public ?string $note = null,
        public ?string $nature = null,
        /** 1 = open the parcel, 2 = do not open. Defaults to 1 on OzonExpress's side. */
        public ?int $open = null,
        public ?bool $fragile = null,
        public ?bool $replace = null,
        /** @var array<int, array{ref: string, qnty: int}>|null */
        public ?array $products = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'tracking-number' => $this->trackingNumber,
            'parcel-receiver' => $this->receiver,
            'parcel-phone' => $this->phone,
            'parcel-city' => $this->cityId,
            'parcel-address' => $this->address,
            'parcel-note' => $this->note,
            'parcel-price' => $this->price,
            'parcel-nature' => $this->nature,
            'parcel-stock' => (int) $this->stock,
            'parcel-open' => $this->open,
            'parcel-fragile' => $this->fragile === null ? null : (int) $this->fragile,
            'parcel-replace' => $this->replace === null ? null : (int) $this->replace,
            'products' => $this->products === null ? null : json_encode($this->products),
        ], fn ($value) => $value !== null);
    }
}
