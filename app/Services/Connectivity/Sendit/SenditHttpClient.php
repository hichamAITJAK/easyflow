<?php

namespace App\Services\Connectivity\Sendit;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Internal HTTP base used by all Sendit service classes.
 * Handles auth headers and base URL — not intended for direct use outside this namespace.
 */
class SenditHttpClient
{
    public const BASE_URL = 'https://app.sendit.ma/api/v1';

    protected PendingRequest $http;

    public function __construct(string $token)
    {
        $this->http = Http::baseUrl(self::BASE_URL)
            ->withToken($token, 'Bearer')
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $endpoint, array $query = []): array
    {
        return $this->http->get(ltrim($endpoint, '/'), $query)->throw()->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function post(string $endpoint, array $data = []): array
    {
        return $this->http->post(ltrim($endpoint, '/'), $data)->throw()->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function put(string $endpoint, array $data = []): array
    {
        return $this->http->put(ltrim($endpoint, '/'), $data)->throw()->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function delete(string $endpoint, array $data = []): array
    {
        $endpoint = ltrim($endpoint, '/');

        return $this->http
            ->send('DELETE', $endpoint, ['json' => empty($data) ? null : $data])
            ->throw()
            ->json() ?? [];
    }
}
