<?php

namespace App\DTOs\Sendit;

/**
 * Represents a Sendit district/city (from listDistricts() / getDistrict()).
 */
readonly class SenditDistrictDTO
{
    public function __construct(
        public string $id,
        public ?string $city,
        public string $name,
        public ?string $arabicName,
        public ?float $price,
        public ?string $delay,
        public bool $isPickupDistrict,
    ) {}

    /**
     * @param  array{id?: string|int, ville?: ?string, name?: string, arabic_name?: ?string, price?: string|float, delais?: ?string, pickup_district?: bool|int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            city: $data['ville'] ?? null,
            name: (string) ($data['name'] ?? ''),
            arabicName: $data['arabic_name'] ?? null,
            price: isset($data['price']) ? (float) $data['price'] : null,
            delay: $data['delais'] ?? null,
            isPickupDistrict: (bool) ($data['pickup_district'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return SenditDistrictDTO[]
     */
    public static function fromList(array $data): array
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = $data['data'] ?? $data;

        return array_map(fn (array $district) => self::fromArray($district), $items);
    }

    /** @return array{id: string, ville: ?string, name: string, arabic_name: ?string, price: ?float, delais: ?string, pickup_district: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ville' => $this->city,
            'name' => $this->name,
            'arabic_name' => $this->arabicName,
            'price' => $this->price,
            'delais' => $this->delay,
            'pickup_district' => $this->isPickupDistrict,
        ];
    }
}
