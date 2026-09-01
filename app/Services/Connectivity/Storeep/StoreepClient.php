<?php

namespace App\Services\Connectivity\Storeep;

/**
 * StoreepClient — the single entry point for all Storeep API interactions.
 *
 * Usage:
 *   $client = new StoreepClient(token: 'your-access-token');
 *
 *   // Access domain-specific services:
 *   $client->products()->listProducts(['page' => 1]);
 *   $client->orders()->listOrders(['page' => 1]);
 *   $client->webhooks()->subscribe('order-created', $url);
 *
 * Credentials are passed to the constructor and propagated to each
 * sub-service. No config values are read from the application — this
 * class is fully self-contained.
 *
 * Storeep's API is read-only for products and orders; there is no store
 * details endpoint and no OAuth, so this client has no auth() or store()
 * sub-service, unlike YouCanClient.
 */
final class StoreepClient
{
    private string $token;

    /**
     * @param  string  $token  Access token (a UUID) created in the merchant's
     *                         Storeep dashboard under Settings → Access tokens
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    // ----------------------------------------------------------------
    // Sub-service accessors
    // ----------------------------------------------------------------

    /**
     * Products — list the store's catalog.
     */
    public function products(): StoreepProductService
    {
        return new StoreepProductService($this->token);
    }

    /**
     * Orders — list the store's orders.
     */
    public function orders(): StoreepOrderService
    {
        return new StoreepOrderService($this->token);
    }

    /**
     * Webhooks — list, create, and delete order webhooks.
     */
    public function webhooks(): StoreepWebhookService
    {
        return new StoreepWebhookService($this->token);
    }
}
