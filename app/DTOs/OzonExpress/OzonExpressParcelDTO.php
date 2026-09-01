<?php

namespace App\DTOs\OzonExpress;

/**
 * Represents an OzonExpress parcel, as returned by add-parcel. The API
 * wraps the actual parcel data in an envelope — ADD-PARCEL.NEW-PARCEL,
 * alongside sibling ADD-PARCEL.CUSTOMER/RESULT/MESSAGE keys — and this DTO
 * is the single place that knows that shape: fromArray() takes the raw,
 * still-wrapped response and unwraps it itself, so no other class needs to
 * know where NEW-PARCEL lives. Sample response:
 *
 * {
 *   "ADD-PARCEL": {
 *     "CUSTOMER": {"RESULT": "SUCCESS", "MESSAGE": "Valid Customer"},
 *     "RESULT": "SUCCESS",
 *     "MESSAGE": "New Parcel Added",
 *     "NEW-PARCEL": {
 *       "TRACKING-NUMBER": "AGA072630058163GP", "RECEIVER": "abdo",
 *       "PHONE": "0666666666", "CITY_ID": 37, "ADDRESS": "address, addres",
 *       "PRICE": 10, "NOTE": "", "DELIVERED-PRICE": 35,
 *       "RETURNED-PRICE": 0, "REFUSED-PRICE": 10
 *     }
 *   }
 * }
 *
 * PRICE is the parcel's COD amount (what the customer pays); the other three
 * prices are what OzonExpress charges the business depending on the
 * parcel's eventual outcome — DELIVERED-PRICE for a successful delivery,
 * RETURNED-PRICE if it comes back in transit, REFUSED-PRICE if the customer
 * refuses it at the door. Only one of the three is ever actually incurred,
 * but all three are quoted upfront.
 *
 * trackingNumber/deliveryCost/returnedCost/refusedCost follow the same
 * naming as every other courier's parcel-result DTO (see SenditParcelDTO),
 * so OrderService::createShipment() can read $parcel->trackingNumber
 * regardless of which courier actually answered.
 */
readonly class OzonExpressParcelDTO
{
    public function __construct(
        public string $trackingNumber,
        public ?string $receiver,
        public ?string $phone,
        public ?string $cityId,
        public ?string $address,
        public ?float $price,
        public ?float $deliveryCost,
        public ?float $returnedCost,
        public ?float $refusedCost,
    ) {}

    /**
     * @param  array{ADD-PARCEL?: array{NEW-PARCEL?: array{TRACKING-NUMBER?: string, RECEIVER?: ?string, PHONE?: ?string, CITY_ID?: string|int, ADDRESS?: ?string, PRICE?: string|float, DELIVERED-PRICE?: string|float, RETURNED-PRICE?: string|float, REFUSED-PRICE?: string|float}}}  $response  The raw, still-enveloped add-parcel response.
     */
    public static function fromArray(array $response): self
    {
        $data = $response['ADD-PARCEL']['NEW-PARCEL'] ?? [];

        return new self(
            trackingNumber: (string) ($data['TRACKING-NUMBER'] ?? ''),
            receiver: $data['RECEIVER'] ?? null,
            phone: $data['PHONE'] ?? null,
            cityId: isset($data['CITY_ID']) ? (string) $data['CITY_ID'] : null,
            address: $data['ADDRESS'] ?? null,
            price: isset($data['PRICE']) ? (float) $data['PRICE'] : null,
            deliveryCost: isset($data['DELIVERED-PRICE']) ? (float) $data['DELIVERED-PRICE'] : null,
            returnedCost: isset($data['RETURNED-PRICE']) ? (float) $data['RETURNED-PRICE'] : null,
            refusedCost: isset($data['REFUSED-PRICE']) ? (float) $data['REFUSED-PRICE'] : null,
        );
    }

    /** @return array{TRACKING-NUMBER: string, RECEIVER: ?string, PHONE: ?string, CITY_ID: ?string, ADDRESS: ?string, PRICE: ?float, DELIVERED-PRICE: ?float, RETURNED-PRICE: ?float, REFUSED-PRICE: ?float} */
    public function toArray(): array
    {
        return [
            'TRACKING-NUMBER' => $this->trackingNumber,
            'RECEIVER' => $this->receiver,
            'PHONE' => $this->phone,
            'CITY_ID' => $this->cityId,
            'ADDRESS' => $this->address,
            'PRICE' => $this->price,
            'DELIVERED-PRICE' => $this->deliveryCost,
            'RETURNED-PRICE' => $this->returnedCost,
            'REFUSED-PRICE' => $this->refusedCost,
        ];
    }
}
