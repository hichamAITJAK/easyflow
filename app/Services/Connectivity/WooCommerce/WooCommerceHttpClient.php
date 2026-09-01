<?php

namespace App\Services\Connectivity\WooCommerce;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Internal HTTP base used by all WooCommerce service classes.
 * Handles auth, base URL and pagination — not intended for direct use
 * outside this namespace.
 *
 * WooCommerce authenticates REST calls with a consumer key/secret pair the
 * merchant generates in WooCommerce > Settings > Advanced > REST API,
 * passed as HTTP Basic Auth over HTTPS. There is no OAuth exchange and no
 * refresh token, so there is no token-refresh retry path anywhere in this
 * integration (same as Storeep).
 *
 * The base URL is per-store: WooCommerce is self-hosted, so every merchant
 * runs their own copy at their own domain. See WooCommerceStoreUrl for how
 * that merchant-supplied host is vetted before we ever call it.
 *
 * Docs: https://woocommerce.github.io/woocommerce-rest-api-docs/
 */
class WooCommerceHttpClient
{
    /**
     * The v3 REST namespace, appended to the merchant's site URL.
     */
    public const API_PATH = '/wp-json/wc/v3';

    /**
     * WooCommerce caps per_page at 100 across every list endpoint.
     */
    public const MAX_PER_PAGE = 100;

    protected PendingRequest $http;

    public function __construct(string $storeUrl, string $consumerKey, string $consumerSecret)
    {
        $this->http = Http::baseUrl(rtrim($storeUrl, '/').self::API_PATH)
            ->withBasicAuth($consumerKey, $consumerSecret)
            ->acceptJson()
            ->asJson()
            // Self-hosted WordPress on shared hosting is frequently slow;
            // these are more generous than the vendor-API integrations need,
            // but still bounded so a hung store can't pin a queue worker.
            ->timeout(30)
            ->connectTimeout(10);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $endpoint, array $query = []): array
    {
        return $this->http->get($endpoint, $query)->throw()->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function post(string $endpoint, array $data = []): array
    {
        return $this->http->post($endpoint, $data)->throw()->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function put(string $endpoint, array $data = []): array
    {
        return $this->http->put($endpoint, $data)->throw()->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function delete(string $endpoint, array $data = []): array
    {
        return $this->http->delete($endpoint, $data)->throw()->json() ?? [];
    }

    /**
     * Fetch one page of a list endpoint, returning both the decoded rows and
     * WooCommerce's own total-page count.
     *
     * WordPress reports pagination in headers rather than in the body:
     * `X-WP-Total` and `X-WP-TotalPages`. Reading the header is what lets a
     * caller stop at the real last page instead of probing until it gets a
     * short page — which would be wrong anyway, since a page can legitimately
     * come back short when rows are filtered by permissions.
     *
     * @param  array<string, mixed>  $query
     * @return array{items: array<int, array<string, mixed>>, totalPages: int}
     */
    protected function getPage(string $endpoint, array $query = []): array
    {
        $response = $this->http->get($endpoint, $query)->throw();

        $body = $response->json();

        return [
            'items' => is_array($body) ? $body : [],
            'totalPages' => self::totalPages($response),
        ];
    }

    /**
     * Read X-WP-TotalPages, defaulting to a single page when the header is
     * absent (some caching layers and security plugins strip it).
     */
    private static function totalPages(Response $response): int
    {
        $header = $response->header('X-WP-TotalPages');

        return $header !== '' && is_numeric($header) ? max(1, (int) $header) : 1;
    }
}
