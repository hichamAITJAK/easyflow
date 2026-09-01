<?php

namespace App\DTOs\Shopify;

/**
 * Represents a Shopify shop/store (from getShop()).
 */
readonly class ShopifyStoreDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $domain,
        public ?string $email,
        public ?string $phone,
        public ?string $currency,
        public ?string $country,
        public ?string $status,
        public ?string $plan,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param array{
     *     shop?: array{
     *         id?: string|null,
     *         name?: string|null,
     *         url?: string|null,
     *         myshopifyDomain?: string|null,
     *         email?: string|null,
     *         phone?: string|null,
     *         currencyCode?: string|null,
     *         primaryDomain?: array{url?: string|null}|null,
     *         plan?: array{displayName?: string|null}|null,
     *         createdAt?: string|null,
     *         updatedAt?: string|null,
     *     }|null,
     *     id?: string|null,
     *     name?: string|null,
     *     url?: string|null,
     *     myshopifyDomain?: string|null,
     *     email?: string|null,
     *     phone?: string|null,
     *     currencyCode?: string|null,
     *     primaryDomain?: array{url?: string|null}|null,
     *     plan?: array{displayName?: string|null}|null,
     *     createdAt?: string|null,
     *     updatedAt?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $shop = $data['shop'] ?? $data;

        return new self(
            id: (string) ($shop['id'] ?? ''),
            name: (string) ($shop['name'] ?? ''),
            domain: $shop['url'] ?? $shop['myshopifyDomain'] ?? null,
            email: $shop['email'] ?? null,
            phone: $shop['phone'] ?? null,
            currency: $shop['currencyCode'] ?? null,
            country: $shop['primaryDomain']['url'] ?? null, // Shopify primaryDomain or country code
            status: null,
            plan: $shop['plan']['displayName'] ?? null,
            createdAt: $shop['createdAt'] ?? null,
            updatedAt: $shop['updatedAt'] ?? null,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     domain: string|null,
     *     email: string|null,
     *     phone: string|null,
     *     currency: string|null,
     *     country: string|null,
     *     status: string|null,
     *     plan: string|null,
     *     created_at: string|null,
     *     updated_at: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'email' => $this->email,
            'phone' => $this->phone,
            'currency' => $this->currency,
            'country' => $this->country,
            'status' => $this->status,
            'plan' => $this->plan,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
