<?php

namespace App\Services\Connectivity\Shopify;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Internal GraphQL HTTP base used by all Shopify service classes.
 *
 * Shopify GraphQL Admin API:
 *   Endpoint : POST https://{shop}.myshopify.com/admin/api/{version}/graphql.json
 *   Auth     : X-Shopify-Access-Token header
 *   Version  : 2026-07 (latest stable)
 *
 * Docs: https://shopify.dev/docs/api/admin-graphql
 *
 * Unlike REST, GraphQL uses a single endpoint for ALL operations.
 * Queries fetch data, Mutations create/update/delete data.
 */
class ShopifyHttpClient
{
    public const API_VERSION = '2026-07';

    protected PendingRequest $http;

    protected string $shop;

    /**
     * @param  string  $shop  The myshopify domain slug (e.g. "my-store" or "my-store.myshopify.com")
     * @param  string  $accessToken  The X-Shopify-Access-Token (from OAuth or admin-generated)
     */
    public function __construct(string $shop, string $accessToken)
    {
        $shop = str_replace('.myshopify.com', '', $shop);
        $this->shop = $shop;

        $endpoint = "https://{$shop}.myshopify.com/admin/api/".self::API_VERSION.'/graphql.json';

        $this->http = Http::baseUrl($endpoint)
            ->withHeader('X-Shopify-Access-Token', $accessToken)
            ->withHeader('Content-Type', 'application/json')
            ->acceptJson();
    }

    /**
     * Execute a GraphQL query or mutation against the Shopify Admin API.
     *
     * @param  string  $query  The GraphQL query or mutation string
     * @param  array<string, mixed>  $variables  Optional variables map for parameterised queries
     * @return array<string, mixed> The full response array including 'data' and 'errors' keys
     */
    protected function graphql(string $query, array $variables = []): array
    {
        $payload = ['query' => $query];

        if (! empty($variables)) {
            $payload['variables'] = $variables;
        }

        $response = $this->http->post('', $payload)->throw();
        $body = $response->json();

        // Surface GraphQL-level errors (these don't cause HTTP 4xx/5xx)
        if (! empty($body['errors'])) {
            /** @var array<int, array<string, mixed>> $errors */
            $errors = $body['errors'];
            $message = collect($errors)->pluck('message')->implode('; ');
            throw new \RuntimeException("Shopify GraphQL error: {$message}");
        }

        return $body['data'] ?? $body;
    }
}
