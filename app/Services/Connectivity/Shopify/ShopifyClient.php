<?php

namespace App\Services\Connectivity\Shopify;

/**
 * ShopifyClient — the single entry point for all Shopify API interactions.
 *
 * This implementation uses the Shopify Admin GraphQL API (2026-07), as recommended
 * by Shopify for all new apps. All requests go to a single endpoint:
 *   POST https://{shop}.myshopify.com/admin/api/2026-07/graphql.json
 *
 * Docs: https://shopify.dev/docs/api/admin-graphql
 *
 * Usage:
 *   $client = new ShopifyClient(shop: 'my-store', accessToken: 'shpat_...');
 *
 *   // Access domain-specific services:
 *   $client->store()->getShop();
 *   $client->orders()->listOrders(first: 20, query: 'financial_status:paid');
 *   $client->products()->getProduct('gid://shopify/Product/1234567890');
 *
 *   // Note: IDs in GraphQL use the global ID format:
 *   //   "gid://shopify/Order/1234567890"
 *   //   "gid://shopify/Product/1234567890"
 *   //   "gid://shopify/ProductVariant/1234567890"
 *
 *   // OAuth (static — no token needed):
 *   $url = ShopifyClient::getAuthorizationUrl(...);
 *   $client = ShopifyClient::fromOAuthCode($shop, $clientId, $clientSecret, $code);
 *
 * Shopify authentication is token-based (OAuth 2.0).
 * There is no login/password API endpoint.
 *
 *
 * All classes are self-contained — no config values are read from
 * the application. Credentials are passed in and propagated.
 */
final class ShopifyClient
{
    private string $shop;

    private string $accessToken;

    /**
     * @param  string  $shop  The myshopify domain slug (e.g. "my-store" or "my-store.myshopify.com")
     * @param  string  $accessToken  The X-Shopify-Access-Token (from OAuth exchange or admin panel)
     */
    public function __construct(string $shop, string $accessToken)
    {
        $this->shop = $shop;
        $this->accessToken = $accessToken;
    }

    // ----------------------------------------------------------------
    // Sub-service accessors
    // ----------------------------------------------------------------

    /**
     * Store — shop info, countries, shipping zones, payment gateways, policies.
     */
    public function store(): ShopifyStoreService
    {
        return new ShopifyStoreService($this->shop, $this->accessToken);
    }

    /**
     * Orders — list, count, get, create, update, cancel, close/open,
     *           fulfillments, and payment transactions.
     */
    public function orders(): ShopifyOrderService
    {
        return new ShopifyOrderService($this->shop, $this->accessToken);
    }

    /**
     * Products — list, count, get, create, update, delete,
     *             variants, images, and collections.
     */
    public function products(): ShopifyProductService
    {
        return new ShopifyProductService($this->shop, $this->accessToken);
    }

    /**
     * Webhooks — subscribe to Shopify event topics.
     */
    public function webhooks(): ShopifyWebhookService
    {
        return new ShopifyWebhookService($this->shop, $this->accessToken);
    }

    // ----------------------------------------------------------------
    // Static OAuth helpers (no token required)
    // ----------------------------------------------------------------

    /**
     * Build the OAuth authorization URL to redirect a merchant to.
     *
     * @param  array<int, string>  $scopes
     *
     * @see ShopifyAuthService::getAuthorizationUrl()
     */
    public static function getAuthorizationUrl(
        string $shop,
        string $clientId,
        string $redirectUri,
        array $scopes,
        string $state
    ): string {
        return ShopifyAuthService::getAuthorizationUrl($shop, $clientId, $redirectUri, $scopes, $state);
    }

    /**
     * Exchange an OAuth authorization code for an access token.
     * Returns a ShopifyClient already authenticated.
     *
     * @see ShopifyAuthService::exchangeToken()
     *
     * @return static A fully authenticated ShopifyClient instance
     */
    public static function fromOAuthCode(
        string $shop,
        string $clientId,
        string $clientSecret,
        string $code
    ): static {
        $response = ShopifyAuthService::exchangeToken($shop, $clientId, $clientSecret, $code);
        $accessToken = $response['access_token'];

        return new self(shop: $shop, accessToken: $accessToken);
    }

    /**
     * Verify an HMAC signature from a Shopify OAuth callback or webhook.
     *
     * @param  array<string, mixed>  $params
     *
     * @see ShopifyAuthService::verifyHmac()
     */
    public static function verifyHmac(string $clientSecret, array $params, string $hmac): bool
    {
        return ShopifyAuthService::verifyHmac($clientSecret, $params, $hmac);
    }
}
