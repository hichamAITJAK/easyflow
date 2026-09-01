<?php

namespace App\DTOs\ForceLog;

/**
 * Represents a ForceLog parcel, as returned by AddParcel, GetParcel, and
 * GetParcels.
 *
 * AddParcel's response nests the parcel under a NEW-PARCEL key while
 * GetParcel returns it under PARCEL and GetParcels returns bare entries of
 * a PARCELS array — fromArray() accepts all three shapes so no caller needs
 * to know which endpoint answered.
 *
 * trackingNumber/deliveryCost/returnedCost/refusedCost follow the same
 * naming as every other courier's parcel-result DTO (see SenditParcelDTO /
 * OzonExpressParcelDTO / ColiixParcelDTO), so
 * OrderService::createShipment() can read $parcel->trackingNumber
 * regardless of which courier actually answered.
 *
 * ForceLog quotes no per-outcome pricing: DELIVERY_FEES is returned by
 * GetParcel (never by AddParcel), and there is no equivalent of
 * OzonExpress's RETURNED-PRICE / REFUSED-PRICE — so returnedCost and
 * refusedCost are always null for this courier.
 */
readonly class ForceLogParcelDTO
{
    public function __construct(
        public string $trackingNumber,
        public ?string $orderNum,
        public ?string $receiver,
        public ?string $phone,
        public ?string $cityName,
        public ?string $address,
        /** The COD amount the customer pays. */
        public ?float $price,
        public ?string $comment,
        public ?string $productNature,
        /** French display label, e.g. "Livré". Feed into mapDeliveryStatus(). */
        public ?string $status,
        /** Technical code, e.g. "DELIVERED". Absent from GetParcel's response. */
        public ?string $statusCode,
        /** Payment situation, French: "Payé" / "Non Payé". */
        public ?string $situation,
        public ?string $creationTime,
        public ?bool $canOpen,
        public ?float $deliveryCost = null,
        public ?float $returnedCost = null,
        public ?float $refusedCost = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  A parcel object, or a response still
     *                                      wrapped in NEW-PARCEL / PARCEL.
     */
    public static function fromArray(array $data): self
    {
        $parcel = $data['NEW-PARCEL'] ?? $data['PARCEL'] ?? $data;

        return new self(
            trackingNumber: (string) ($parcel['TRACKING_NUMBER'] ?? ''),
            orderNum: isset($parcel['ORDER_NUM']) ? (string) $parcel['ORDER_NUM'] : null,
            receiver: $parcel['RECEIVER'] ?? null,
            phone: isset($parcel['PHONE']) ? (string) $parcel['PHONE'] : null,
            cityName: $parcel['CITY_NAME'] ?? null,
            address: $parcel['ADDRESS'] ?? null,
            // PRICE comes back as a string on every endpoint that returns it.
            price: isset($parcel['PRICE']) ? (float) $parcel['PRICE'] : null,
            comment: $parcel['COMMENT'] ?? null,
            productNature: $parcel['PRODUCT_NATURE'] ?? null,
            status: $parcel['STATUS'] ?? null,
            statusCode: $parcel['STATUS_CODE'] ?? null,
            situation: $parcel['SITUATION'] ?? null,
            creationTime: $parcel['CREATION_TIME'] ?? null,
            // CAN_OPEN is a "YES"/"NO" string on read endpoints, but was sent
            // as 1/0 on create — accept either rather than trusting one form.
            canOpen: self::parseCanOpen($parcel['CAN_OPEN'] ?? null),
            deliveryCost: isset($parcel['DELIVERY_FEES']) ? (float) $parcel['DELIVERY_FEES'] : null,
        );
    }

    /**
     * Return a copy of this parcel carrying a delivery cost.
     *
     * ForceLog's add-parcel response has no fee field at all, so the cost
     * is resolved separately from the account's own city pricing and
     * grafted on here (see ForceLogService::resolveDeliveryCost). An
     * existing non-null cost — the DELIVERY_FEES a GetParcel response
     * carries — always wins, since that is the courier's own figure for
     * this specific parcel rather than a per-city quote.
     */
    public function withDeliveryCost(?float $deliveryCost): self
    {
        if ($this->deliveryCost !== null || $deliveryCost === null) {
            return $this;
        }

        return new self(
            trackingNumber: $this->trackingNumber,
            orderNum: $this->orderNum,
            receiver: $this->receiver,
            phone: $this->phone,
            cityName: $this->cityName,
            address: $this->address,
            price: $this->price,
            comment: $this->comment,
            productNature: $this->productNature,
            status: $this->status,
            statusCode: $this->statusCode,
            situation: $this->situation,
            creationTime: $this->creationTime,
            canOpen: $this->canOpen,
            deliveryCost: $deliveryCost,
            returnedCost: $this->returnedCost,
            refusedCost: $this->refusedCost,
        );
    }

    /**
     * @param  array<string, mixed>  $response  A GET-PARCELS payload, or a bare PARCELS array.
     * @return ForceLogParcelDTO[]
     */
    public static function fromList(array $response): array
    {
        $parcels = $response['PARCELS'] ?? $response;

        if (! is_array($parcels)) {
            return [];
        }

        return array_map(
            fn (array $parcel) => self::fromArray($parcel),
            array_values($parcels),
        );
    }

    /**
     * Normalize ForceLog's several spellings of a yes/no flag.
     */
    private static function parseCanOpen(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            mb_strtoupper((string) $value),
            ['YES', 'OUI', '1', 'TRUE'],
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'TRACKING_NUMBER' => $this->trackingNumber,
            'ORDER_NUM' => $this->orderNum,
            'RECEIVER' => $this->receiver,
            'PHONE' => $this->phone,
            'CITY_NAME' => $this->cityName,
            'ADDRESS' => $this->address,
            'PRICE' => $this->price,
            'COMMENT' => $this->comment,
            'PRODUCT_NATURE' => $this->productNature,
            'STATUS' => $this->status,
            'STATUS_CODE' => $this->statusCode,
            'SITUATION' => $this->situation,
            'CREATION_TIME' => $this->creationTime,
            'CAN_OPEN' => $this->canOpen,
            'DELIVERY_FEES' => $this->deliveryCost,
        ];
    }
}
