<?php

namespace App\DTOs\Lightfunnels;

/**
 * Represents a Lightfunnels order shipping/billing address.
 */
readonly class LightfunnelsAddressDTO
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $line1,
        public ?string $line2,
        public ?string $city,
        public ?string $state,
        public ?string $country,
        public ?string $zip,
        public ?string $phone,
    ) {}

    /**
     * @param  array{first_name?: ?string, last_name?: ?string, line1?: ?string, line2?: ?string, city?: ?string, state?: ?string, country?: ?string, zip?: ?string, phone?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            line1: $data['line1'] ?? null,
            line2: $data['line2'] ?? null,
            city: $data['city'] ?? null,
            state: $data['state'] ?? null,
            country: $data['country'] ?? null,
            zip: $data['zip'] ?? null,
            phone: $data['phone'] ?? null,
        );
    }

    /**
     * @return array{first_name: ?string, last_name: ?string, first_line: ?string, second_line: ?string, city: ?string, province: ?string, country: ?string, zip: ?string, phone: ?string}
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'first_line' => $this->line1,
            'second_line' => $this->line2,
            'city' => $this->city,
            'province' => $this->state,
            'country' => $this->country,
            'zip' => $this->zip,
            'phone' => $this->phone,
        ];
    }
}
