<?php

namespace App\DTOs\WooCommerce;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a WooCommerce billing or shipping address.
 *
 * Both address objects share a shape, except that `email` and `phone` are
 * only present on `billing` in most WooCommerce versions — shipping gained
 * a `phone` field in 5.6 and still has no email. That asymmetry matters for
 * a COD workflow, where the phone number IS the order: see
 * WooCommerceOrderDTO::customerPhone().
 *
 * @implements Arrayable<string, mixed>
 */
readonly class WooCommerceAddressDTO implements Arrayable
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $company,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $state,
        public ?string $postcode,
        public ?string $countryCode,
        public ?string $email,
        public ?string $phone,
    ) {}

    /**
     * @param  array{
     *     first_name?: ?string,
     *     last_name?: ?string,
     *     company?: ?string,
     *     address_1?: ?string,
     *     address_2?: ?string,
     *     city?: ?string,
     *     state?: ?string,
     *     postcode?: ?string,
     *     country?: ?string,
     *     email?: ?string,
     *     phone?: ?string,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            firstName: self::clean($data['first_name'] ?? null),
            lastName: self::clean($data['last_name'] ?? null),
            company: self::clean($data['company'] ?? null),
            addressLine1: self::clean($data['address_1'] ?? null),
            addressLine2: self::clean($data['address_2'] ?? null),
            city: self::clean($data['city'] ?? null),
            state: self::clean($data['state'] ?? null),
            postcode: self::clean($data['postcode'] ?? null),
            countryCode: self::clean($data['country'] ?? null),
            email: self::clean($data['email'] ?? null),
            phone: self::clean($data['phone'] ?? null),
        );
    }

    /**
     * WooCommerce returns empty strings rather than nulls for address
     * fields the customer left blank. Normalizing them to null keeps
     * "absent" distinguishable from "present but empty" downstream — which
     * is what lets the order DTO fall back from shipping to billing.
     */
    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Whether this address carries anything at all.
     *
     * A WooCommerce order for a digital or local-pickup purchase has a
     * `shipping` object present but entirely blank, so its mere existence
     * is not evidence of a usable delivery address.
     */
    public function isEmpty(): bool
    {
        return $this->firstName === null
            && $this->lastName === null
            && $this->addressLine1 === null
            && $this->city === null
            && $this->postcode === null;
    }

    /**
     * The customer's full name, or null when neither part is set.
     */
    public function name(): ?string
    {
        $name = trim(implode(' ', array_filter([$this->firstName, $this->lastName])));

        return $name === '' ? null : $name;
    }

    /**
     * @return array{
     *     first_name: ?string,
     *     last_name: ?string,
     *     company: ?string,
     *     first_line: ?string,
     *     second_line: ?string,
     *     city: ?string,
     *     state: ?string,
     *     postcode: ?string,
     *     country_code: ?string,
     *     email: ?string,
     *     phone: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            // `first_line`/`second_line` rather than address_1/address_2:
            // OrderSyncService::upsertOrder() builds customer_address from
            // those two keys, and this is the shared shape every platform's
            // address DTO emits.
            'first_line' => $this->addressLine1,
            'second_line' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country_code' => $this->countryCode,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }
}
