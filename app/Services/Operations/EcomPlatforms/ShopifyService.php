<?php

namespace App\Services\Operations\EcomPlatforms;

use App\DTOs\Shopify\ShopifyOrderDTO;
use App\DTOs\Shopify\ShopifyProductDTO;
use App\DTOs\Shopify\ShopifyStoreDTO;
use App\DTOs\Shopify\ShopifyTokenDTO;
use App\Interfaces\EcomPlatformInterface;
use App\Models\ShopifyConnectionRequest;
use App\Models\Store;
use App\Services\Connectivity\Shopify\ShopifyAuthService;
use App\Services\Connectivity\Shopify\ShopifyClient;
use App\Services\Connectivity\Shopify\ShopifyCredentials;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Handles the Shopify OAuth connect flow — the authorize URL and the
 * code-for-token exchange — and the per-store API bridge. Every merchant
 * connects with their own Shopify app, so the keys come from the pending
 * connection request (before the store exists) or from the store's own
 * credentials (after), never from one platform-wide .env app, except as a
 * fallback for stores connected before this.
 */
class ShopifyService implements EcomPlatformInterface
{
    public function __construct(private readonly ?Store $store = null) {}

    /**
     * Normalize a shop domain (accepts "my-store" or the full myshopify.com URL).
     */
    public static function normalizeShop(string $shop): string
    {
        return Str::of($shop)
            ->trim()
            ->replaceMatches('/^https?:\/\//', '')
            ->before('.myshopify.com')
            ->toString();
    }

    /**
     * The Shopify authorize URL for a pending connection: the merchant's
     * own app id, our callback, the scopes, and the request's state so the
     * callback can find the request again.
     */
    public function authorizationUrlFor(ShopifyConnectionRequest $pending): string
    {
        return ShopifyAuthService::getAuthorizationUrl(
            shop: $pending->shop,
            clientId: $pending->client_id,
            redirectUri: route('stores.connect.shopify.callback', absolute: true),
            scopes: config('services.shopify.scopes'),
            state: $pending->state,
        );
    }

    /**
     * Exchange an OAuth authorization code for an access token, with the
     * app keys the merchant connected with.
     *
     * Single responsibility: return the token response — persisting it
     * against a store is the caller's job (the store may not exist yet).
     */
    public function authentication(string $code, string $shop, string $clientId, string $clientSecret): ShopifyTokenDTO
    {
        return ShopifyTokenDTO::fromArray(
            ShopifyAuthService::exchangeToken(
                shop: $shop,
                clientId: $clientId,
                clientSecret: $clientSecret,
                code: $code,
            )
        );
    }

    /**
     * Build an authenticated Shopify client from this store's stored credentials.
     */
    private function client(): ShopifyClient
    {
        $credentials = json_decode($this->store->api_credentials, true) ?? [];

        return new ShopifyClient($this->store->external_store_id, $credentials['access_token'] ?? '');
    }

    // ----------------------------------------------------------------
    // Prepare Connectivity Bridge
    // ----------------------------------------------------------------

    /**
     * Run a Shopify API call, transparently refreshing the access token and
     * retrying once if the call fails with an unauthorized (401) response.
     *
     * Only offline access tokens requested with `expiring=1` carry a
     * refresh token — that's what this app requests, so every stored
     * Shopify connection is expected to have one.
     */
    private function execute(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (RequestException $e) {
            if ($e->response->status() !== 401) {
                throw $e;
            }

            $this->refreshToken();

            return $callback();
        }
    }

    /**
     * Exchange the stored refresh token for a new access token and persist it.
     */
    private function refreshToken(): void
    {
        $credentials = json_decode($this->store->api_credentials, true) ?? [];
        $keys = ShopifyCredentials::fromCredentials($credentials);

        $response = ShopifyAuthService::refreshToken(
            shop: $this->store->external_store_id,
            clientId: $keys->clientId,
            clientSecret: $keys->clientSecret,
            refreshToken: $credentials['refresh_token'] ?? '',
        );

        $token = ShopifyTokenDTO::fromArray($response);

        // The app keys stay with the new token: they are the store's, not
        // the token's.
        $this->store->update([
            'api_credentials' => json_encode([
                ...$token->toArray(),
                'client_id' => $keys->clientId,
                'client_secret' => $keys->clientSecret,
            ]),
        ]);
    }

    // ----------------------------------------------------------------
    // Implementation Of EcomPlatformInterface Interface
    // ----------------------------------------------------------------

    /**
     * Get store details for this store via the Shopify API.
     */
    public function getStoreDetails(): array
    {
        return $this->execute(fn () => ShopifyStoreDTO::fromArray(
            $this->client()->store()->getShop()
        ))->toArray();
    }

    /**
     * List products for this store via the Shopify API, fetching every page.
     *
     * @return ShopifyProductDTO[]
     */
    public function loadProducts(): array
    {
        $pages = $this->fetchAllPages(
            fn (string $after) => $this->execute(
                fn () => $this->client()->products()->listProducts(after: $after)
            ),
            fn (array $page) => $page['products']['pageInfo'] ?? null,
        );

        return array_merge(...array_map(
            fn (array $page) => ShopifyProductDTO::fromList($page),
            $pages
        ));
    }

    /**
     * List orders for this store via the Shopify API, fetching every page.
     *
     * @return ShopifyOrderDTO[]
     */
    public function loadOrders(?CarbonInterface $since = null): array
    {
        $query = $this->createdAtQuery($since);

        $pages = $this->fetchAllPages(
            fn (string $after) => $this->execute(
                fn () => $this->client()->orders()->listOrders(after: $after, query: $query)
            ),
            fn (array $page) => $page['orders']['pageInfo'] ?? null,
        );

        return array_merge(...array_map(
            fn (array $page) => ShopifyOrderDTO::fromList($page),
            $pages
        ));
    }

    /**
     * Subscribe this store to Shopify's ORDERS_CREATE webhook topic, plus
     * APP_UNINSTALLED so the store can be marked disconnected the moment the
     * merchant removes the app (its token is revoked at that point, so every
     * later API call would 401).
     *
     * Failure is logged rather than thrown — a store must still be usable
     * (via the nightly reconciliation sync) even if this subscription
     * fails, per UC-4.
     *
     * The three mandatory privacy webhooks (customers/data_request,
     * customers/redact, shop/redact) are deliberately absent here: Shopify
     * delivers those from the app's Partner Dashboard configuration and
     * rejects attempts to subscribe to them through the API.
     */
    public function registerOrderWebhook(): void
    {
        $topics = [
            'ORDERS_CREATE' => route('webhooks.shopify.receive', $this->store),
            'APP_UNINSTALLED' => route('webhooks.shopify.app.uninstalled'),
        ];

        foreach ($topics as $topic => $callbackUrl) {
            try {
                $this->client()->webhooks()->subscribe($topic, $callbackUrl);
            } catch (Throwable $e) {
                Log::warning('Shopify webhook subscription failed', [
                    'store_id' => $this->store->id,
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Unsubscribe this store from Shopify's order-creation webhook.
     *
     * Not implemented yet — ShopifyWebhookService has no delete mutation,
     * and registerOrderWebhook() doesn't persist the subscription id that
     * would be needed to call one. A store disconnected today leaves its
     * Shopify-side webhook subscription active; Shopify will eventually
     * prune it after enough failed deliveries once the app's credentials
     * (and thus its ability to receive them) are gone.
     */
    public function deregisterOrderWebhook(): void {}

    /**
     * Shopify's search-syntax filter for "created at or after $since",
     * used by both the product and order queries.
     *
     * Docs: https://shopify.dev/docs/api/usage/search-syntax — `created_at`
     * accepts an ISO 8601 value, and `>=` is an inclusive range prefix.
     * Returns an empty string when there's no cutoff, which the GraphQL
     * `query` argument treats as "no filter".
     */
    private function createdAtQuery(?CarbonInterface $since): string
    {
        if ($since === null) {
            return '';
        }

        return 'created_at:>='.$since->toIso8601String();
    }

    /**
     * Fetch every page of a Shopify GraphQL connection, following its
     * cursor-based `pageInfo.hasNextPage` / `pageInfo.endCursor`.
     *
     * @param  callable(string): array<string, mixed>  $fetchPage  Called with the cursor to fetch after (empty for the first page).
     * @param  callable(array<string, mixed>): (array<string, mixed>|null)  $getPageInfo  Extracts the `pageInfo` node from a page's response.
     * @return array<int, array<string, mixed>> The raw GraphQL `data` payload for each page fetched.
     */
    private function fetchAllPages(callable $fetchPage, callable $getPageInfo): array
    {
        $pages = [];
        $after = '';

        do {
            $response = $fetchPage($after);
            $pages[] = $response;

            $pageInfo = $getPageInfo($response);
            $after = $pageInfo['endCursor'] ?? '';
        } while ($pageInfo['hasNextPage'] ?? false);

        return $pages;
    }
}
