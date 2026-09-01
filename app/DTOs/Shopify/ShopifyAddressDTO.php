<?php

namespace App\DTOs\Shopify;

/**
 * Represents a Shopify address.
 *
 * toArray() emits first_line/second_line for address1/address2 — the same
 * convention YouCanAddressDTO and LightfunnelsAddressDTO already use, so
 * OrderSyncService::upsertOrder() can read a synced order's shipping
 * address the same way regardless of platform.
 */
readonly class ShopifyAddressDTO
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $address1,
        public ?string $address2,
        public ?string $city,
        public ?string $province,
        public ?string $country,
        public ?string $zip,
        public ?string $phone,
        public ?string $company,
    ) {}

    /**
     * @param array{
     *     firstName?: string|null,
     *     lastName?: string|null,
     *     address1?: string|null,
     *     address2?: string|null,
     *     city?: string|null,
     *     province?: string|null,
     *     country?: string|null,
     *     zip?: string|null,
     *     phone?: string|null,
     *     company?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['firstName'] ?? null,
            lastName: $data['lastName'] ?? null,
            address1: $data['address1'] ?? null,
            address2: $data['address2'] ?? null,
            city: $data['city'] ?? null,
            province: $data['province'] ?? null,
            country: $data['country'] ?? null,
            zip: $data['zip'] ?? null,
            phone: $data['phone'] ?? null,
            company: $data['company'] ?? null,
        );
    }

    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    /**
     * @return array{
     *     first_name: string|null,
     *     last_name: string|null,
     *     first_line: string|null,
     *     second_line: string|null,
     *     city: string|null,
     *     province: string|null,
     *     country: string|null,
     *     zip: string|null,
     *     phone: string|null,
     *     company: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'first_line' => $this->address1,
            'second_line' => $this->address2,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'zip' => $this->zip,
            'phone' => $this->phone,
            'company' => $this->company,
        ];
    }
}
