<?php

namespace App\Services\Connectivity\Shopify;

use App\Models\Store;

/**
 * The Shopify app keys a store was connected with.
 *
 * Every merchant connects with their own Shopify app, so the client id and
 * secret live in the store's encrypted credentials next to the access
 * token. Stores connected before that (with the platform-wide app in
 * .env) carry no keys of their own and fall back to the configured ones,
 * so they keep working without a reconnect.
 */
final class ShopifyCredentials
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
    ) {}

    public static function fromStore(Store $store): self
    {
        return self::fromCredentials(json_decode((string) $store->api_credentials, true) ?: []);
    }

    /**
     * @param  array<string, mixed>  $credentials  The decoded api_credentials blob.
     */
    public static function fromCredentials(array $credentials): self
    {
        $clientId = $credentials['client_id'] ?? null;
        $clientSecret = $credentials['client_secret'] ?? null;

        return new self(
            is_string($clientId) && $clientId !== '' ? $clientId : (string) config('services.shopify.client_id'),
            is_string($clientSecret) && $clientSecret !== '' ? $clientSecret : (string) config('services.shopify.client_secret'),
        );
    }

    /** The platform-wide app from .env, for stores with no keys of their own. */
    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.shopify.client_id'),
            (string) config('services.shopify.client_secret'),
        );
    }
}
