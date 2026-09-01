<?php

namespace App\DTOs\Ameex;

/**
 * Represents an Ameex parcel, as returned by the add-parcel and parcel-info
 * operations.
 *
 * fromArray() takes the already-unwrapped `api` payload (see
 * AmeexClient::unwrap), and accepts the parcel either at that level or
 * nested under a `parcel`/`data` key.
 *
 * trackingNumber/deliveryCost/returnedCost/refusedCost follow the same
 * naming as every other courier's parcel-result DTO (see SenditParcelDTO /
 * OzonExpressParcelDTO / ColiixParcelDTO / ForceLogParcelDTO), so
 * OrderService::createShipment() can read $parcel->trackingNumber
 * regardless of which courier actually answered.
 *
 * Ameex's documented request and response fields carry no pricing of any
 * kind — there is no equivalent of OzonExpress's DELIVERED-PRICE or
 * ForceLog's DELIVERY_FEES — so all three cost properties are always null
 * for this courier, exactly as they are for Coliix. If a future response
 * does carry a fee, the fallback keys below will pick it up.
 *
 * Key spellings are accepted defensively: the vendor's Postman collection
 * ships no saved example responses, and the live API cannot be read without
 * credentials, so the exact casing Ameex returns is unverified. Every
 * accessor falls back through the plausible spellings rather than assuming
 * one.
 */
readonly class AmeexParcelDTO
{
    public function __construct(
        public string $trackingNumber,
        public ?string $orderNum,
        public ?string $receiver,
        public ?string $phone,
        public ?string $city,
        public ?string $address,
        /** The COD amount the customer pays. */
        public ?float $price,
        public ?string $comment,
        public ?string $product,
        /** Ameex's own status label, fed into mapDeliveryStatus(). */
        public ?string $status,
        public ?string $statusCode,
        public ?string $createdAt,
        public ?float $deliveryCost = null,
        public ?float $returnedCost = null,
        public ?float $refusedCost = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  An unwrapped `api` payload, or a bare parcel object.
     */
    public static function fromArray(array $payload): self
    {
        $parcel = $payload['parcel'] ?? $payload['data'] ?? $payload;

        if (! is_array($parcel)) {
            $parcel = [];
        }

        return new self(
            trackingNumber: (string) (self::pick($parcel, ['code', 'parcel_code', 'ParcelCode', 'tracking_number']) ?? ''),
            orderNum: self::string($parcel, ['order_num', 'order_number']),
            receiver: self::string($parcel, ['receiver', 'name']),
            phone: self::string($parcel, ['phone']),
            city: self::string($parcel, ['city', 'city_name']),
            address: self::string($parcel, ['address']),
            price: self::float($parcel, ['cod', 'price', 'crbt']),
            comment: self::string($parcel, ['comment', 'note']),
            product: self::string($parcel, ['product', 'product_name']),
            status: self::string($parcel, ['statut', 'status', 'statut_name', 'status_name']),
            statusCode: self::string($parcel, ['statut_code', 'status_code']),
            createdAt: self::string($parcel, ['date', 'created_at', 'creation_date']),
            deliveryCost: self::float($parcel, ['delivery_price', 'delivered_price', 'fee']),
            returnedCost: self::float($parcel, ['returned_price', 'return_price']),
            refusedCost: self::float($parcel, ['refused_price']),
        );
    }

    /**
     * @param  array<string, mixed>  $payload  An unwrapped list payload, or a bare array of parcels.
     * @return AmeexParcelDTO[]
     */
    public static function fromList(array $payload): array
    {
        // `data`/`parcels` come out of an untyped body, so either may hold a
        // scalar (or null) rather than the list this expects — hence the
        // is_array check before the rows are walked. Falling back to the
        // payload itself covers a response that is already a bare list.
        $parcels = array_key_exists('data', $payload)
            ? $payload['data']
            : ($payload['parcels'] ?? $payload);

        if (! is_array($parcels)) {
            return [];
        }

        // array_filter has already dropped every non-array row, so each one
        // reaching fromArray() is a parcel-shaped array.
        return array_values(array_map(
            fn (array $parcel) => self::fromArray($parcel),
            array_filter($parcels, 'is_array'),
        ));
    }

    /**
     * First present value among several candidate key spellings.
     *
     * @param  array<string, mixed>  $data
     * @param  string[]  $keys
     */
    private static function pick(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string[]  $keys
     */
    private static function string(array $data, array $keys): ?string
    {
        $value = self::pick($data, $keys);

        return $value === null ? null : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string[]  $keys
     */
    private static function float(array $data, array $keys): ?float
    {
        $value = self::pick($data, $keys);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->trackingNumber,
            'order_num' => $this->orderNum,
            'receiver' => $this->receiver,
            'phone' => $this->phone,
            'city' => $this->city,
            'address' => $this->address,
            'cod' => $this->price,
            'comment' => $this->comment,
            'product' => $this->product,
            'statut' => $this->status,
            'statut_code' => $this->statusCode,
            'date' => $this->createdAt,
            'delivery_price' => $this->deliveryCost,
            'returned_price' => $this->returnedCost,
            'refused_price' => $this->refusedCost,
        ];
    }
}
