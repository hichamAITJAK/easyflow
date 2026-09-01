<?php

namespace App\DTOs\OzonExpress;

/**
 * Represents a city covered by OzonExpress, from GET /cities.
 */
readonly class OzonExpressCityDTO
{
    public function __construct(
        public string $id,
        public ?string $ref,
        public string $name,
        public ?float $deliveredPrice,
        public ?float $returnedPrice,
        public ?float $refusedPrice,
    ) {}

    /**
     * @param  array{ID?: string|int, REF?: ?string, NAME?: string, DELIVERED-PRICE?: string|float, RETURNED-PRICE?: string|float, REFUSED-PRICE?: string|float}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['ID'] ?? ''),
            ref: $data['REF'] ?? null,
            name: (string) ($data['NAME'] ?? ''),
            deliveredPrice: isset($data['DELIVERED-PRICE']) ? (float) $data['DELIVERED-PRICE'] : null,
            returnedPrice: isset($data['RETURNED-PRICE']) ? (float) $data['RETURNED-PRICE'] : null,
            refusedPrice: isset($data['REFUSED-PRICE']) ? (float) $data['REFUSED-PRICE'] : null,
        );
    }

    /**
     * @param  array{CITIES?: array<int, array<string, mixed>>}|array<int, array<string, mixed>>  $data
     * @return OzonExpressCityDTO[]
     */
    public static function fromList(array $data): array
    {
        $cities = $data['CITIES'] ?? $data;

        return array_map(fn (array $city) => self::fromArray($city), array_values($cities));
    }

    /** @return array{ID: string, REF: ?string, NAME: string, DELIVERED-PRICE: ?float, RETURNED-PRICE: ?float, REFUSED-PRICE: ?float} */
    public function toArray(): array
    {
        return [
            'ID' => $this->id,
            'REF' => $this->ref,
            'NAME' => $this->name,
            'DELIVERED-PRICE' => $this->deliveredPrice,
            'RETURNED-PRICE' => $this->returnedPrice,
            'REFUSED-PRICE' => $this->refusedPrice,
        ];
    }
}
