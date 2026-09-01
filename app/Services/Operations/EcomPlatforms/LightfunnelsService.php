<?php

namespace App\Services\Operations\EcomPlatforms;

use App\DTOs\Lightfunnels\LightfunnelsOrderDTO;
use App\DTOs\Lightfunnels\LightfunnelsProductDTO;
use App\DTOs\Lightfunnels\LightfunnelsStoreDTO;
use App\DTOs\Lightfunnels\LightfunnelsTokenDTO;
use App\Interfaces\EcomPlatformInterface;
use App\Models\Product;
use App\Models\Store;
use App\Services\Connectivity\Lightfunnels\LightfunnelsAuthService;
use App\Services\Connectivity\Lightfunnels\LightfunnelsClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Handles the Lightfunnels OAuth connect flow: generating the authorization
 * URL and exchanging the callback code for an access token. Mirrors
 * YouCanService's cache-token connect/authentication pattern, since no
 * store exists yet at the point the merchant is redirected to Lightfunnels.
 *
 * Lightfunnels access tokens are account-scoped, not store-scoped: one
 * token can see every store, product, and order on the merchant's account.
 * A merchant's account can hold several stores, and Lightfunnels' own
 * products/orders queries return the whole account's data with no
 * store-filtering argument — so loadProducts()/loadOrders() filter the
 * account-wide results down to this store's own data client-side.
 */
class LightfunnelsService implements EcomPlatformInterface
{
    public function __construct(private readonly ?Store $store = null) {}

    /**
     * Cache the business awaiting this connection and return the
     * Lightfunnels authorization URL to redirect the merchant to, with a
     * `state` param that maps back to them once Lightfunnels redirects back.
     */
    public function connect(int $businessId): string
    {
        $token = Str::random(40);

        Cache::put(self::cacheKey($token), $businessId, now()->addMinutes(30));

        return $this->authorization($token);
    }

    /**
     * Resolve (and forget) the business id cached for a connect token.
     */
    public static function resolveBusinessId(string $token): ?int
    {
        return Cache::pull(self::cacheKey($token));
    }

    /**
     * Build the Lightfunnels OAuth authorization URL to redirect the merchant to.
     */
    public function authorization(string $token): string
    {
        return LightfunnelsAuthService::getAuthorizationUrl(
            clientId: $this->getClientId(),
            redirectUri: route('stores.connect.lightfunnels.callback', absolute: true),
            scopes: config('services.lightfunnels.scopes'),
            accountId: (string) config('services.lightfunnels.account_id'),
            state: $token,
        );
    }

    /**
     * Exchange an OAuth authorization code for an access token.
     *
     * Single responsibility: return the token response — persisting it
     * against a store is the caller's job (the store may not exist yet).
     */
    public function authentication(string $code): LightfunnelsTokenDTO
    {
        return LightfunnelsTokenDTO::fromArray(
            LightfunnelsAuthService::exchangeToken(
                clientId: $this->getClientId(),
                clientSecret: (string) config('services.lightfunnels.client_secret'),
                code: $code,
            )
        );
    }

    /**
     * Build the cache key used to map a connect token to a business id.
     */
    private static function cacheKey(string $token): string
    {
        return "lightfunnels_connect.{$token}";
    }

    /**
     * Retrieve and validate the Lightfunnels client ID configuration.
     */
    private function getClientId(): string
    {
        $clientId = config('services.lightfunnels.client_id');

        if (! is_string($clientId) || trim($clientId) === '') {
            throw new \RuntimeException('Lightfunnels client ID is not configured or is invalid.');
        }

        return $clientId;
    }

    /**
     * Build an authenticated Lightfunnels client from this store's stored credentials.
     */
    private function client(): LightfunnelsClient
    {
        $credentials = json_decode($this->store->api_credentials, true) ?? [];

        return new LightfunnelsClient($credentials['access_token'] ?? '');
    }

    // ----------------------------------------------------------------
    // Prepare Connectivity Bridge
    // ----------------------------------------------------------------

    /**
     * Run a Lightfunnels API call.
     *
     * Lightfunnels access tokens are documented as permanent (no
     * refresh_token is issued alongside them), so unlike Shopify/YouCan
     * there is no refresh-and-retry step here — a failed call is simply
     * allowed to propagate.
     */
    private function execute(callable $callback): mixed
    {
        return $callback();
    }

    // ----------------------------------------------------------------
    // Implementation Of EcomPlatformInterface Interface
    // ----------------------------------------------------------------

    /**
     * Get store details for this store via the Lightfunnels API.
     */
    public function getStoreDetails(): array
    {
        return $this->execute(fn () => LightfunnelsStoreDTO::fromArray(
            $this->client()->store()->getStore($this->store->external_store_id)
        ))->toArray();
    }

    /**
     * List products for this store via the Lightfunnels API, fetching every
     * page of the account's catalog and keeping only the products attached
     * to this store.
     *
     * @return LightfunnelsProductDTO[]
     */
    public function loadProducts(): array
    {
        $products = $this->loadAccountProducts();

        return array_values(array_filter(
            $products,
            fn (LightfunnelsProductDTO $product) => in_array($this->store->external_store_id, $product->storeIds, true)
        ));
    }

    /**
     * List orders for this store via the Lightfunnels API, fetching every
     * page of the account's orders and keeping only the ones that contain
     * at least one line item for a product attached to this store.
     *
     * Lightfunnels orders carry no direct store reference through the
     * GraphQL API (only webhook payloads include a store_id), so store
     * membership is inferred from each order's line items instead — matched
     * against this store's already-synced products, since ProductSyncService
     * always runs before OrderSyncService and this avoids re-walking the
     * account's entire product catalog a second time.
     *
     * @return LightfunnelsOrderDTO[]
     */
    /**
     * `created_at` is a documented filter parameter for the `orders` query
     * (https://developer.lightfunnels.com/orders — "Supported filter
     * parameters: order_by order_dir status financial_status
     * fulfillment_status created_at product_id").
     *
     * The docs do NOT document its value grammar — every published example
     * is `order_by:id order_dir:desc` — so the `>=`/ISO-date form below is
     * the conventional reading rather than a verified one. That is safe
     * here for one specific reason: OrderSyncService re-applies the cutoff
     * to whatever comes back, so if this term is misparsed or ignored the
     * result is wasted bandwidth, never a full-history import.
     */
    public function loadOrders(?CarbonInterface $since = null): array
    {
        $storeProductIds = Product::where('store_id', $this->store->id)
            ->pluck('external_product_id')
            ->all();

        // `order_by:id` is listOrders()'s own default and drives the cursor
        // pagination below, so the date term is appended to it rather than
        // replacing it — dropping the ordering would break paging.
        $query = 'order_by:id';

        if ($since !== null) {
            $query .= ' created_at:>='.$since->toDateString();
        }

        $pages = $this->fetchAllPages(
            fn (string $after) => $this->execute(
                fn () => $this->client()->orders()->listOrders(after: $after, query: $query)
            ),
            fn (array $page) => $page['orders']['pageInfo'] ?? null,
        );

        $orders = array_merge(...array_map(
            fn (array $page) => LightfunnelsOrderDTO::fromList($page),
            $pages
        ));

        return array_values(array_filter(
            $orders,
            fn (LightfunnelsOrderDTO $order) => array_intersect($order->productIds(), $storeProductIds) !== []
        ));
    }

    /**
     * No-op: Lightfunnels webhook subscription
     * (LightfunnelsClient::webhooks()->createWebhook()) needs a receiving
     * endpoint first — not wired up yet, orders arrive via re-sync for now.
     */
    public function registerOrderWebhook(): void
    {
        //
    }

    /**
     * No-op: mirrors registerOrderWebhook() — nothing is ever subscribed,
     * so there's nothing to unsubscribe.
     */
    public function deregisterOrderWebhook(): void
    {
        //
    }

    /**
     * Fetch every product on the account, unfiltered by store.
     *
     * @return LightfunnelsProductDTO[]
     */
    private function loadAccountProducts(): array
    {
        $pages = $this->fetchAllPages(
            fn (string $after) => $this->execute(
                fn () => $this->client()->products()->listProducts(after: $after)
            ),
            fn (array $page) => $page['products']['pageInfo'] ?? null,
        );

        return array_merge(...array_map(
            fn (array $page) => LightfunnelsProductDTO::fromList($page),
            $pages
        ));
    }

    /**
     * Fetch every page of a Lightfunnels GraphQL connection, following its
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
