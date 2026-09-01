<?php

namespace App\DTOs\YouCan;

/**
 * Represents a YouCan store (from getDetails(), i.e. GET /me — YouCan has
 * no dedicated "store" resource, the authenticated store's own info is
 * returned at the account root). Field names/shapes below are taken from
 * the actual API response, not the (partly incorrect) API docs:
 * `store_id` (not `id`), `slug`, `currency` as {code, symbol}, `status`
 * as an int with a separate human-readable `status_text`, no `plan`/
 * `created_at`/`updated_at`.
 */
readonly class YouCanStoreDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $slug,
        public ?string $domain,
        public ?string $email,
        public ?string $phone,
        public ?string $currencyCode,
        public ?string $currencySymbol,
        public ?string $logo,
        public ?string $status,
    ) {}

    /**
     * @param  array{
     *     store?: array<string, mixed>,
     *     store_id?: string|int,
     *     id?: string|int,
     *     name?: ?string,
     *     slug?: ?string,
     *     domain?: ?string,
     *     url?: ?string,
     *     email?: ?string,
     *     phone?: ?string,
     *     currency?: array{code?: ?string, symbol?: ?string}|string|null,
     *     logo?: ?string,
     *     status_text?: ?string,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $store = $data['store'] ?? $data;
        $currency = $store['currency'] ?? null;

        return new self(
            id: (string) ($store['store_id'] ?? $store['id'] ?? ''),
            name: (string) ($store['name'] ?? ''),
            slug: $store['slug'] ?? null,
            domain: $store['domain'] ?? $store['url'] ?? null,
            email: $store['email'] ?? null,
            phone: $store['phone'] ?? null,
            currencyCode: is_array($currency) ? ($currency['code'] ?? null) : $currency,
            currencySymbol: is_array($currency) ? ($currency['symbol'] ?? null) : null,
            logo: $store['logo'] ?? null,
            status: $store['status_text'] ?? null,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     slug: ?string,
     *     domain: ?string,
     *     email: ?string,
     *     phone: ?string,
     *     currency_code: ?string,
     *     currency_symbol: ?string,
     *     logo: ?string,
     *     status: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'email' => $this->email,
            'phone' => $this->phone,
            'currency_code' => $this->currencyCode,
            'currency_symbol' => $this->currencySymbol,
            'logo' => $this->logo,
            'status' => $this->status,
        ];
    }
}
