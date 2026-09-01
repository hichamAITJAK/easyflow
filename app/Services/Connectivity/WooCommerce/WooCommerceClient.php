<?php

namespace App\Services\Connectivity\WooCommerce;

/**
 * WooCommerceClient — the single entry point for all WooCommerce API
 * interactions.
 *
 * Usage:
 *   $client = new WooCommerceClient('https://shop.example.com', 'ck_...', 'cs_...');
 *
 *   // Access domain-specific services:
 *   $client->products()->listProducts(['page' => 1]);
 *   $client->orders()->listOrders(['page' => 1]);
 *   $client->webhooks()->subscribe('order.created', $url, $secret, 'EasyFlow');
 *   $client->system()->status();
 *
 * Credentials are passed to the constructor and propagated to each
 * sub-service. No config values are read from the application — this class
 * is fully self-contained.
 *
 * Unlike every other platform we integrate, the base URL is per-store:
 * WooCommerce is self-hosted, so each merchant runs their own install at
 * their own domain. That host must be vetted with WooCommerceStoreUrl
 * before a client is built against it.
 */
final class WooCommerceClient
{
    public function __construct(
        private readonly string $storeUrl,
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
    ) {}

    // ----------------------------------------------------------------
    // Sub-service accessors
    // ----------------------------------------------------------------

    /**
     * Products — list the store's catalog and its variations.
     */
    public function products(): WooCommerceProductService
    {
        return new WooCommerceProductService($this->storeUrl, $this->consumerKey, $this->consumerSecret);
    }

    /**
     * Orders — list and retrieve the store's orders.
     */
    public function orders(): WooCommerceOrderService
    {
        return new WooCommerceOrderService($this->storeUrl, $this->consumerKey, $this->consumerSecret);
    }

    /**
     * Webhooks — list, create and delete order webhooks.
     */
    public function webhooks(): WooCommerceWebhookService
    {
        return new WooCommerceWebhookService($this->storeUrl, $this->consumerKey, $this->consumerSecret);
    }

    /**
     * System — store details and connectivity checks.
     */
    public function system(): WooCommerceSystemService
    {
        return new WooCommerceSystemService($this->storeUrl, $this->consumerKey, $this->consumerSecret);
    }
}
