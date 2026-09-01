<?php

namespace App\DTOs\ForceLog;

/**
 * Request payload to create a ForceLog parcel, matching the JSON fields
 * expected by /customer/Parcels/AddParcel. Pass toArray() to
 * ForceLogClient::addParcel().
 *
 * ForceLog enforces max lengths server-side (ORDER_NUM 20, RECEIVER 50,
 * PHONE 14, CITY 50, ADDRESS 100, COMMENT 100, PRODUCT_NATURE 100) and
 * rejects the whole request when one is exceeded. Those limits are applied
 * here instead, truncating rather than failing: an order with a long address
 * is still worth shipping, and losing the tail of a comment is a far smaller
 * problem than a parcel that never gets created. ORDER_NUM is the exception
 * — see toArray().
 */
readonly class ForceLogNewParcelDTO
{
    /**
     * @param  array<string, int|string|null>|null  $stock  Stock references keyed by
     *                                                      reference, e.g. ['KA2NP' => 2]. A null quantity sends the
     *                                                      bare reference, which the API accepts.
     */
    public function __construct(
        public string $orderNum,
        public string $receiver,
        public string $phone,
        /** City name or code — ForceLog's AddParcel takes either, unlike RelaunchZone which needs the numeric id. */
        public string $city,
        public string $address,
        public float $cod = 0.0,
        public ?string $comment = null,
        public ?string $productNature = null,
        public bool $canOpen = true,
        public bool $fragile = false,
        /** Stock references as "REF:QTY" pairs, e.g. ['KA2NP' => 2]. Quantity is optional per the API. */
        public ?array $stock = null,
        public ?string $carton = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            // Not truncated: ORDER_NUM is how the business reconciles this
            // parcel against its own order, so a silently shortened value
            // would be worse than the API rejecting it outright.
            'ORDER_NUM' => $this->orderNum,
            'RECEIVER' => $this->truncate($this->receiver, 50),
            'PHONE' => $this->truncate($this->phone, 14),
            'CITY' => $this->truncate($this->city, 50),
            'ADDRESS' => $this->truncate($this->address, 100),
            'COD' => $this->cod,
            'CAN_OPEN' => $this->canOpen ? 1 : 0,
            'FRAGILE' => $this->fragile ? 1 : 0,
            'COMMENT' => $this->truncate($this->comment, 100),
            'PRODUCT_NATURE' => $this->truncate($this->productNature, 100),
            'CARTON' => $this->carton,
            'STOCK' => $this->stockReferences(),
        ];

        return array_filter($data, fn ($value) => $value !== null);
    }

    /**
     * Flatten stock references into ForceLog's comma-separated
     * "reference:quantity" string, e.g. "KA2NP:2,KH2LC:1".
     */
    private function stockReferences(): ?string
    {
        if (empty($this->stock)) {
            return null;
        }

        $pairs = [];

        foreach ($this->stock as $reference => $quantity) {
            $pairs[] = $quantity === null ? (string) $reference : "{$reference}:{$quantity}";
        }

        return implode(',', $pairs);
    }

    /**
     * Cut a value to ForceLog's documented column width, preserving null.
     * mb_substr rather than substr so a multibyte character is never split
     * mid-sequence into mojibake.
     */
    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
