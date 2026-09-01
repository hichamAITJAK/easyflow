<?php

namespace App\DTOs\Lightfunnels;

/**
 * Represents a Lightfunnels store's profile (from getStore() / listStores()).
 */
readonly class LightfunnelsStoreDTO
{
    public function __construct(
        public string $id,
        public ?string $uid,
        public string $name,
        public ?string $slug,
        public ?string $domain,
        public ?string $email,
        public ?string $currency,
        public ?string $address,
        public ?string $legalName,
    ) {}

    /**
     * @param  array{store?: array<string, mixed>, id?: mixed, uid?: ?string, name?: mixed, slug?: ?string, defaultDomain?: ?string, email?: ?string, currency?: ?string, address?: ?string, legal_name?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        $store = $data['store'] ?? $data;

        return new self(
            id: (string) ($store['id'] ?? ''),
            uid: $store['uid'] ?? null,
            name: (string) ($store['name'] ?? ''),
            slug: $store['slug'] ?? null,
            domain: $store['defaultDomain'] ?? null,
            email: $store['email'] ?? null,
            currency: $store['currency'] ?? null,
            address: $store['address'] ?? null,
            legalName: $store['legal_name'] ?? null,
        );
    }

    /**
     * @return array{id: string, uid: ?string, name: string, slug: ?string, domain: ?string, email: ?string, currency: ?string, address: ?string, legal_name: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'name' => $this->name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'email' => $this->email,
            'currency' => $this->currency,
            'address' => $this->address,
            'legal_name' => $this->legalName,
        ];
    }
}
