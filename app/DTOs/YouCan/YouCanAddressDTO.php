<?php

namespace App\DTOs\YouCan;

/**
 * Represents a physical address returned by the YouCan API.
 * Shared by orders (shipping) and customers.
 *
 * Field names follow the customer address shape from the YouCan API
 * (first_line, second_line, zip_code, country_code, ...); order shipping
 * addresses are mapped onto the same shape via fallback keys.
 */
readonly class YouCanAddressDTO
{
    public function __construct(
        public ?string $id,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $company,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $region,
        public ?string $countryCode,
        public ?string $zipCode,
        public ?string $phone,
        public bool $isDefault,
    ) {}

    /**
     * @param  array{
     *     id?: string|int,
     *     first_name?: ?string,
     *     firstName?: ?string,
     *     last_name?: ?string,
     *     lastName?: ?string,
     *     company?: ?string,
     *     first_line?: ?string,
     *     address1?: ?string,
     *     address?: ?string,
     *     second_line?: ?string,
     *     address2?: ?string,
     *     city?: ?string,
     *     region?: ?string,
     *     province?: ?string,
     *     state?: ?string,
     *     country_code?: ?string,
     *     country?: ?string,
     *     zip_code?: ?string,
     *     zip?: ?string,
     *     postal_code?: ?string,
     *     phone?: ?string,
     *     is_default?: bool,
     *     default?: bool,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            firstName: $data['first_name'] ?? $data['firstName'] ?? null,
            lastName: $data['last_name'] ?? $data['lastName'] ?? null,
            company: $data['company'] ?? null,
            addressLine1: $data['first_line'] ?? $data['address1'] ?? $data['address'] ?? null,
            addressLine2: $data['second_line'] ?? $data['address2'] ?? null,
            city: $data['city'] ?? null,
            region: $data['region'] ?? $data['province'] ?? $data['state'] ?? null,
            countryCode: $data['country_code'] ?? $data['country'] ?? null,
            zipCode: $data['zip_code'] ?? $data['zip'] ?? $data['postal_code'] ?? null,
            phone: $data['phone'] ?? null,
            isDefault: (bool) ($data['is_default'] ?? $data['default'] ?? false),
        );
    }

    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    /**
     * @return array{
     *     id: ?string,
     *     first_name: ?string,
     *     last_name: ?string,
     *     company: ?string,
     *     first_line: ?string,
     *     second_line: ?string,
     *     city: ?string,
     *     region: ?string,
     *     country_code: ?string,
     *     zip_code: ?string,
     *     phone: ?string,
     *     is_default: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'first_line' => $this->addressLine1,
            'second_line' => $this->addressLine2,
            'city' => $this->city,
            'region' => $this->region,
            'country_code' => $this->countryCode,
            'zip_code' => $this->zipCode,
            'phone' => $this->phone,
            'is_default' => $this->isDefault,
        ];
    }
}
