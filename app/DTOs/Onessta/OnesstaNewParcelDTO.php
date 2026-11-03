<?php

namespace App\DTOs\Onessta;

/**
 * Request payload to create an Onessta parcel, matching the JSON body of
 * parcels/add. Pass toArray() to OnesstaClient::addParcel().
 *
 * Two Onessta-specific shapes worth noting:
 *
 *  - `city` and `pickup_city` are {id, name} objects carrying Onessta's own
 *    city ids, not plain names.
 *  - `code` is OUR identifier for the parcel and must be unique at Onessta
 *    ("Code already used"); Onessta answers with its own `code` as well,
 *    which is the one tracking and webhooks use.
 */
readonly class OnesstaNewParcelDTO
{
    public function __construct(
        public string $code,
        public string $receiver,
        public string $phone,
        public float $price,
        public string $cityId,
        public string $cityName,
        public string $address,
        public ?string $pickupCityId = null,
        public ?string $pickupCityName = null,
        public ?string $note = null,
        public ?string $productNature = null,
        public bool $canOpen = true,
        public bool $replace = false,
        /** Stock refs separated by ';', a ref repeated once per unit. */
        public ?string $sku = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'code' => $this->code,
            'sku' => $this->sku,
            'receiver' => $this->receiver,
            'phone' => $this->phone,
            'price' => $this->price,
            'city' => ['id' => $this->cityId, 'name' => $this->cityName],
            'pickup_city' => $this->pickupCityId
                ? ['id' => $this->pickupCityId, 'name' => (string) $this->pickupCityName]
                : null,
            'address' => $this->address,
            'note' => $this->note,
            'product_nature' => $this->productNature,
            'can_open' => $this->canOpen ? 1 : 0,
            'replace' => $this->replace ? 1 : 0,
        ];

        return array_filter($data, fn ($value) => $value !== null);
    }
}
