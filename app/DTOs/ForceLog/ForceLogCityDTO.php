<?php

namespace App\DTOs\ForceLog;

/**
 * Represents one city ForceLog serves.
 *
 * /customer/Cities returns a bare object keyed by numeric city id rather
 * than an array — the id lives in the KEY, not in the value:
 *
 *   {"34": {"CODE": "CAS", "NAME": "Casablanca", "D_FEES": "30", "D_FEES_SAME_CITY": "20"}}
 *
 * so fromMap() is the entry point, not a plain fromList().
 *
 * The three identifiers are not interchangeable in ForceLog's own API:
 * AddParcel and CreateRequest accept the CODE or the NAME, while
 * RelaunchZone and the return flow require the numeric id. Both are kept
 * here for that reason.
 *
 * Fees are returned as strings and cast to float here.
 */
readonly class ForceLogCityDTO
{
    public function __construct(
        public string $id,
        public ?string $code,
        public string $name,
        public ?float $deliveryFees,
        public ?float $deliveryFeesSameCity,
    ) {}

    /**
     * @param  array{CODE?: ?string, NAME?: ?string, D_FEES?: string|float|null, D_FEES_SAME_CITY?: string|float|null}  $data
     */
    public static function fromArray(string $id, array $data): self
    {
        return new self(
            id: $id,
            code: $data['CODE'] ?? null,
            name: (string) ($data['NAME'] ?? ''),
            deliveryFees: isset($data['D_FEES']) ? (float) $data['D_FEES'] : null,
            deliveryFeesSameCity: isset($data['D_FEES_SAME_CITY']) ? (float) $data['D_FEES_SAME_CITY'] : null,
        );
    }

    /**
     * Build the full city list from the id-keyed map /customer/Cities returns.
     *
     * Values are typed loosely because the live response is not uniform: it
     * carries non-city scalars alongside the city rows (an `AUTH` entry, in
     * the shape ForceLog actually returns), so each value is checked before
     * being read as a city rather than assumed to be one.
     *
     * @param  array<string, mixed>  $map
     * @return ForceLogCityDTO[]
     */
    public static function fromMap(array $map): array
    {
        $cities = [];

        foreach ($map as $id => $data) {
            if (! is_array($data)) {
                continue;
            }

            $cities[] = self::fromArray((string) $id, $data);
        }

        return $cities;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ID' => $this->id,
            'CODE' => $this->code,
            'NAME' => $this->name,
            'D_FEES' => $this->deliveryFees,
            'D_FEES_SAME_CITY' => $this->deliveryFeesSameCity,
        ];
    }
}
