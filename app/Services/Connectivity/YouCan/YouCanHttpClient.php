<?php

namespace App\Services\Connectivity\YouCan;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Internal HTTP base used by all YouCan service classes.
 * Handles auth headers and base URL — not intended for direct use outside this namespace.
 */
class YouCanHttpClient
{
    public const BASE_URL = 'https://api.youcan.shop';

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
