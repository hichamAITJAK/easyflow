<?php

namespace App\Services\Connectivity\YouCan;

/**
 * YouCanClient — the single entry point for all YouCan API interactions.
 *
 * Usage:
 *   $client = new YouCanClient(token: 'your-api-token');
 *
 *   // Access domain-specific services:
 *   $client->auth()->login($email, $password);
 *   $client->store()->getDetails();
 *   $client->orders()->listOrders(['page' => 1]);
 *   $client->products()->getProduct($id);
 *   $client->customers()->getCustomerWithOrders($customerId);
 *
 * Credentials are passed to the constructor and propagated to each
 * sub-service. No config values are read from the application — this
 * class is fully self-contained.
 */
final class YouCanClient
{
    private string $token;

    /**
     * @param  string  $token  Bearer token obtained from the YouCan API (login or OAuth exchange)
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Create a YouCanClient using email + password credentials.
     * Performs the login call and stores the returned token automatically.
     *
     * @param  string  $email  YouCan account email
     * @param  string  $password  YouCan account password
     * @return static A fully authenticated client instance
     */
    public static function fromCredentials(string $email, string $password): static
    {
        // Use a temporary unauthenticated-style client just for the login call
        $tempClient = new self(token: '');
        $response = $tempClient->auth()->login($email, $password);
        $token = $response['token'];

        return new self(token: $token);
    }

    // ----------------------------------------------------------------
    // Sub-service accessors
    // ----------------------------------------------------------------

    /**
     * Authentication — login, OAuth, store switching, logout.
     */
    public function auth(): YouCanAuthService
    {
        return new YouCanAuthService($this->token);
    }

    /**
     * Store — details, config, profits, packs, seller multi-store management.
     */
    public function store(): YouCanStoreService
    {
        return new YouCanStoreService($this->token);
    }

    /**
     * Orders — list, get, create, and update statuses.
     */
    public function orders(): YouCanOrderService
    {
        return new YouCanOrderService($this->token);
    }

    /**
     * Customers — list, get (optionally with orders/addresses), create,
     * update, delete, and manage addresses.
     */
    public function customers(): YouCanCustomerService
    {
        return new YouCanCustomerService($this->token);
    }

    /**
     * Products — list, get, create, update, categories, and reviews.
     */
    public function products(): YouCanProductService
    {
        return new YouCanProductService($this->token);
    }

    /**
     * Webhooks — subscribe to REST Hook events.
     */
    public function webhooks(): YouCanWebhookService
    {
        return new YouCanWebhookService($this->token);
    }
}
