<?php

namespace App\DTOs\Storeep;

/**
 * Represents an address on a Storeep order.
 *
 * Storeep returns addresses as a list on the order, each tagged with a
 * `type` (`shipping`, `billing`, ...), and carries a wide set of
 * locality fields — many of which are region-specific and usually null
 * (`neighborhood`, `district`, `commune`, `province`, `national_id`).
 * Those are preserved rather than dropped, since for COD delivery in
 * Morocco a neighborhood is often the only routable detail present.
 *
 * `toArray()` emits the shared address key shape every platform's address
 * DTO uses (first_line, second_line, zip_code, country_code, ...), because
 * that is what OrderSyncService reads.
 */
readonly class StoreepAddressDTO
{
    public function __construct(
        public ?string $type,
        public ?string $fullName,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $company,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $region,
        public ?string $zipCode,
        public ?string $phone,
        public ?string $whatsapp,
        public ?string $neighborhood,
        public ?string $district,
        public ?string $apartment,
        public ?string $floor,
        public ?string $commune,
        public ?string $province,
        public ?string $note,
    ) {}

    /**
     * @param  array{
     *     type?: ?string,
     *     fullname?: ?string,
     *     firstname?: ?string,
     *     lastname?: ?string,
     *     company?: ?string,
     *     address1?: ?string,
     *     address2?: ?string,
     *     city?: ?string,
     *     state_or_region?: ?string,
     *     postal_code?: ?string,
     *     phone?: ?string,
     *     whatsapp?: ?string,
     *     neighborhood?: ?string,
     *     district?: ?string,
     *     apartment?: ?string,
     *     floor?: ?string,
     *     commune?: ?string,
     *     province?: ?string,
     *     note?: ?string,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? null,
            fullName: $data['fullname'] ?? null,
            firstName: $data['firstname'] ?? null,
            lastName: $data['lastname'] ?? null,
            company: $data['company'] ?? null,
            addressLine1: $data['address1'] ?? null,
            addressLine2: $data['address2'] ?? null,
            city: $data['city'] ?? null,
            region: $data['state_or_region'] ?? null,
            zipCode: $data['postal_code'] ?? null,
            phone: $data['phone'] ?? null,
            whatsapp: $data['whatsapp'] ?? null,
            neighborhood: $data['neighborhood'] ?? null,
            district: $data['district'] ?? null,
            apartment: $data['apartment'] ?? null,
            floor: $data['floor'] ?? null,
            commune: $data['commune'] ?? null,
            province: $data['province'] ?? null,
            note: $data['note'] ?? null,
        );
    }

    /**
     * Pick the address to ship to out of an order's address list: the one
     * explicitly tagged `shipping`, else the first present.
     *
     * @param  array<int, array<string, mixed>>  $addresses
     */
    public static function shippingFromList(array $addresses): ?self
    {
        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (($address['type'] ?? null) === 'shipping') {
                return self::fromArray($address);
            }
        }

        return self::fromArray($addresses[0]);
    }

    public function name(): string
    {
        return $this->fullName ?? trim("{$this->firstName} {$this->lastName}");
    }

    /**
     * The street-level line, with Storeep's sub-address fields folded in.
     *
     * `address2` alone frequently omits detail that is present only in the
     * apartment/floor fields, and OrderSyncService joins first_line +
     * second_line into the single address string an agent reads out on the
     * phone — so anything routable belongs in one of those two lines.
     */
    private function secondLine(): ?string
    {
        $parts = array_filter([
            $this->addressLine2,
            $this->apartment !== null ? "Apt {$this->apartment}" : null,
            $this->floor !== null ? "Floor {$this->floor}" : null,
            $this->neighborhood,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @return array{
     *     type: ?string,
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
     *     whatsapp: ?string,
     *     neighborhood: ?string,
     *     district: ?string,
     *     commune: ?string,
     *     province: ?string,
     *     note: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'first_line' => $this->addressLine1,
            'second_line' => $this->secondLine(),
            'city' => $this->city,
            'region' => $this->region,
            // Storeep's address carries no country field — the order's
            // `market` (a 2-letter code) is the closest thing, and it is
            // applied by StoreepOrderDTO rather than guessed at here.
            'country_code' => null,
            'zip_code' => $this->zipCode,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'neighborhood' => $this->neighborhood,
            'district' => $this->district,
            'commune' => $this->commune,
            'province' => $this->province,
            'note' => $this->note,
        ];
    }
}
