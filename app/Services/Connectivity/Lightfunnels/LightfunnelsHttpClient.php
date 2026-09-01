<?php

namespace App\Services\Connectivity\Lightfunnels;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Internal GraphQL HTTP base used by all Lightfunnels service classes.
 *
 * Lightfunnels API V2:
 *   Endpoint : POST https://services.lightfunnels.com/api/v2
 *   Auth     : Bearer access token
 *
 * Like Shopify, Lightfunnels uses a single GraphQL endpoint for all operations.
 */
class LightfunnelsHttpClient
{
    public const BASE_URL = 'https://services.lightfunnels.com/api/v2';

    protected PendingRequest $http;

    public function __construct(string $accessToken)
    {
        $this->http = Http::baseUrl(self::BASE_URL)
            ->withToken($accessToken)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Execute a GraphQL query or mutation against the Lightfunnels API.
     *
     * @param  string  $query  The GraphQL query or mutation string
     * @param  array<string, mixed>  $variables  Optional variables map for parameterised queries
     * @return array<string, mixed> The 'data' portion of the response
     */
    protected function graphql(string $query, array $variables = []): array
    {
        $payload = ['query' => $query];

        if (! empty($variables)) {
            $payload['variables'] = $variables;
        }

        $response = $this->http->post('', $payload)->throw();
        $body = $response->json();

        if (! empty($body['errors'])) {
            /** @var array<int, array<string, mixed>> $errors */
            $errors = $body['errors'];
            $message = collect($errors)->pluck('message')->implode('; ');

            throw new \RuntimeException("Lightfunnels GraphQL error: {$message}");
        }

        return $body['data'] ?? $body;
    }
}
