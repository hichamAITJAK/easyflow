<?php

namespace App\DTOs\Onessta;

/**
 * An Onessta parcel, as returned by parcels/add.
 *
 * trackingNumber/deliveryCost/returnedCost/refusedCost follow the same
 * naming as every other courier's parcel-result DTO (see SenditParcelDTO /
 * AmeexParcelDTO), so OrderService::createShipment() can read them
 * regardless of which courier answered.
 *
 * Onessta's parcel answer carries no price of its own; the three costs
 * come from the destination city's fees in the client's city list (see
 * OnesstaService::cityFees()) and are passed in by the service.
 */
readonly class OnesstaParcelDTO
{
    public function __construct(
        public string $trackingNumber,
        public ?int $id,
        public ?string $externalCode,
        public ?string $receiver,
        public ?string $cityName,
        public ?string $address,
        /** The COD amount the customer pays. */
        public ?float $price,
        /** Onessta's status code, fed into mapDeliveryStatus(). */
        public ?string $status,
        /** Payment state: NOT_PAID, PAID, INVOICED... */
        public ?string $situation,
        public ?string $createdAt,
        public ?float $deliveryCost = null,
        public ?float $returnedCost = null,
        public ?float $refusedCost = null,
    ) {}

    /**
     * @param  array<string, mixed>  $parcel
     * @param  array{delivered?: ?float, returned?: ?float, refused?: ?float}  $fees
     */
    public static function fromArray(array $parcel, array $fees = []): self
    {
        $string = fn (string $key) => isset($parcel[$key]) && $parcel[$key] !== '' ? (string) $parcel[$key] : null;

        return new self(
            trackingNumber: (string) ($parcel['code'] ?? ''),
            id: isset($parcel['id']) ? (int) $parcel['id'] : null,
            externalCode: $string('external_code') ?? $string('external_id'),
            receiver: $string('receiver'),
            cityName: $string('city_name'),
            address: $string('address'),
            price: is_numeric($parcel['price'] ?? null) ? (float) $parcel['price'] : null,
            status: $string('status'),
            situation: $string('situation'),
            createdAt: $string('created_at'),
            deliveryCost: $fees['delivered'] ?? null,
            returnedCost: $fees['returned'] ?? null,
            refusedCost: $fees['refused'] ?? null,
        );
    }
}
