<?php

namespace App\Services\Connectivity\Lightfunnels;

/**
 * LightfunnelsClient — the single entry point for all Lightfunnels API interactions.
 *
 * Usage:
 *   $client = new LightfunnelsClient(accessToken: 'your-access-token');
 *
 *   // Access domain-specific services:
 *   $client->store()->getPixels();
 *   $client->orders()->listOrders();
 *   $client->products()->getProduct($id);
 *   $client->webhooks()->createWebhook('order/confirmed', $callbackUrl);
 *
 *   // OAuth (static — no token needed):
 *   $url = LightfunnelsClient::getAuthorizationUrl(...);
 *   $client = LightfunnelsClient::fromOAuthCode($clientId, $clientSecret, $code);
 *
 * Credentials are passed to the constructor and propagated to each
 * sub-service. No config values are read from the application — this
 * class is fully self-contained.
 */
final class LightfunnelsClient
{
    private string $accessToken;

    /**
     * @param  string  $accessToken  Bearer token obtained from the Lightfunnels OAuth exchange
     */
    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    // ----------------------------------------------------------------
    // Sub-service accessors
    // ----------------------------------------------------------------

    /**
     * Store — tracking pixels, shipping locations, and integrations.
     */
    public function store(): LightfunnelsStoreService
    {
        return new LightfunnelsStoreService($this->accessToken);
    }

    /**
     * Orders — list, get, update, cancel, fulfill, and mark as paid.
     */
    public function orders(): LightfunnelsOrderService
    {
        return new LightfunnelsOrderService($this->accessToken);
    }

    /**
     * Products — list, get, create, update, delete, and add to store.
     */
    public function products(): LightfunnelsProductService
    {
        return new LightfunnelsProductService($this->accessToken);
    }

    /**
     * Webhooks — list, subscribe, and delete.
     */
    public function webhooks(): LightfunnelsWebhookService
    {
        return new LightfunnelsWebhookService($this->accessToken);
    }

    // ----------------------------------------------------------------
    // Static OAuth helpers (no token required)
    // ----------------------------------------------------------------

    /**
     * Build the OAuth consent screen URL to redirect a merchant to.
     *
     * @param  array<int, string>  $scopes
     *
     * @see LightfunnelsAuthService::getAuthorizationUrl()
     */
    public static function getAuthorizationUrl(
        string $clientId,
        string $redirectUri,
        array $scopes,
        string $accountId,
        string $state
    ): string {
        return LightfunnelsAuthService::getAuthorizationUrl($clientId, $redirectUri, $scopes, $accountId, $state);
    }

    /**
     * Exchange an OAuth authorization code for an access token.
     * Returns a LightfunnelsClient already authenticated.
     *
     * @see LightfunnelsAuthService::exchangeToken()
     *
     * @return static A fully authenticated LightfunnelsClient instance
     */
    public static function fromOAuthCode(
        string $clientId,
        string $clientSecret,
        string $code
    ): static {
        $response = LightfunnelsAuthService::exchangeToken($clientId, $clientSecret, $code);
        $accessToken = $response['access_token'];

        return new self(accessToken: $accessToken);
    }
}
