<?php

namespace App\Services\Connectivity\Storeep;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Internal HTTP base used by all Storeep service classes.
 * Handles auth headers and base URL — not intended for direct use outside this namespace.
 *
 * Storeep authenticates with a static access token (a UUID the merchant
 * creates in their dashboard), passed as a bearer token. Unlike YouCan and
 * Shopify there is no OAuth exchange and no refresh token, so there is no
 * token-refresh retry path anywhere in this integration.
 *
 * Docs: https://docs.storeep.com
 */
class StoreepHttpClient
{
    public const BASE_URL = 'https://api.storeep.com/v1';

    protected PendingRequest $http;

    public function __construct(string $token)
    {
        $this->http = Http::baseUrl(self::BASE_URL)
            ->withToken($token)
            ->acceptJson()
            ->asJson();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $endpoint, array $query = []): array
    {
        return $this->http->get($endpoint, $query)->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function post(string $endpoint, array $data = []): array
    {
        return $this->http->post($endpoint, $data)->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function put(string $endpoint, array $data = []): array
    {
        return $this->http->put($endpoint, $data)->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function delete(string $endpoint, array $data = []): array
    {
        return $this->http->delete($endpoint, $data)->throw()->json();
    }
}
