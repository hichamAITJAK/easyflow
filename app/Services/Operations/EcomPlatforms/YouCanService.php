<?php

namespace App\Services\Operations\EcomPlatforms;

use App\DTOs\YouCan\YouCanAddressDTO;
use App\DTOs\YouCan\YouCanCustomerDTO;
use App\DTOs\YouCan\YouCanOrderDTO;
use App\DTOs\YouCan\YouCanProductDTO;
use App\DTOs\YouCan\YouCanStoreDTO;
use App\DTOs\YouCan\YouCanTokenDTO;
use App\Interfaces\EcomPlatformInterface;
use App\Models\Store;
use App\Services\Connectivity\YouCan\YouCanAuthService;
use App\Services\Connectivity\YouCan\YouCanClient;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class YouCanService implements EcomPlatformInterface
{
    public function __construct(private readonly ?Store $store = null) {}

    /**
     * Cache the business awaiting this connection, keyed by this browser's
     * session id, and return the YouCan authorization URL to redirect the
     * merchant to.
     *
     * YouCan's allowed redirect URLs can't include query params, so unlike
     * Shopify/Lightfunnels we can't round-trip a `state` token through the
     * URL — the session id (same browser, fixed callback URL) is what
     * correlates the callback back to this business instead. A short TTL
     * keeps this pending-connection window tight, rather than relying on
     * the (much longer) global session lifetime.
     */
    public function connect(int $businessId): string
    {
        Cache::put(self::cacheKey(), $businessId, now()->addMinutes(5));

        return $this->authorization();
    }

    /**
     * Resolve (and forget) the business id cached for this browser's
     * pending connection.
     */
    public static function resolveBusinessId(): ?int
    {
        return Cache::pull(self::cacheKey());
    }

    /**
     * Build the cache key correlating this browser's session to its
     * pending YouCan connection.
     */
    private static function cacheKey(): string
    {
        return 'youcan_connect.'.session()->getId();
    }

    /**
     * Build the YouCan OAuth authorization URL to redirect the merchant to.
     */
    public function authorization(): string
    {
        $redirectUri = route('stores.connect.youcan.callback', absolute: true);

        return (new YouCanAuthService(''))->getAuthorizationUrl(
            clientId: $this->getClientId(),
            redirectUri: $redirectUri,
            scopes: (array) config('services.youcan.scopes'),
        );
    }

    /**
     * Exchange an OAuth authorization code for an access token.
     *
     * Single responsibility: return the token response — persisting it
     * against a store is the caller's job (the store may not exist yet).
     */
    public function authentication(string $code): YouCanTokenDTO
    {
        $response = (new YouCanAuthService(''))->exchangeToken(
            clientId: $this->getClientId(),
            clientSecret: (string) config('services.youcan.client_secret'),
            code: $code,
            redirectUri: (string) route('stores.connect.youcan.callback', absolute: true),
        );

        return YouCanTokenDTO::fromArray($response);
    }

    /**
     * Retrieve and validate the YouCan client ID configuration.
     */
    private function getClientId(): string
    {
        $clientId = config('services.youcan.client_id');

        if (! is_string($clientId) || trim($clientId) === '') {
            throw new \RuntimeException('YouCan client ID is not configured or is invalid.');
        }

        return $clientId;
    }

    // ----------------------------------------------------------------
    // Prepare Connectivity Bridge
    // ----------------------------------------------------------------

    /**
     * Run a YouCan API call, transparently refreshing the access token and
     * retrying once if the call fails with an unauthorized (401) response.
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

        $response = (new YouCanAuthService(''))->exchangeToken(
            clientId: $this->getClientId(),
            clientSecret: (string) config('services.youcan.client_secret'),
            code: $credentials['refresh_token'] ?? '',
            redirectUri: (string) config('services.youcan.redirect_uri'),
            grantType: 'refresh_token',
        );

        $token = YouCanTokenDTO::fromArray($response);

        $this->store->update([
            'api_credentials' => json_encode($token->toArray()),
        ]);
    }

    /**
     * Build an authenticated YouCan client from this store's stored credentials.
     */
    private function client(): YouCanClient
    {
        $credentials = json_decode($this->store->api_credentials, true) ?? [];

        return new YouCanClient($credentials['access_token'] ?? '');
    }

    // ----------------------------------------------------------------
    // Implementation Of EcomPlatformInterface Interface
    // ----------------------------------------------------------------

    /**
     * List products for this store via the YouCan API.
     *
     * @return YouCanStoreDTO[]
     */
    public function getStoreDetails(): array
    {
        return $this->execute(fn () => YouCanStoreDTO::fromArray(
            $this->client()->store()->getDetails()
        ));
    }

    /**
     * List products for this store via the YouCan API, fetching every page.
     *
     * `include=variants` is required: without it YouCan's /products only
     * carries `has_variants` / `variants_count` / `variant_options`, never
     * the variant rows themselves, so every product would sync with no
     * variants.
     *
     * @return YouCanProductDTO[]
     */
    public function loadProducts(): array
    {
        $pages = $this->fetchAllPages(
            fn (int $page) => $this->execute(
                fn () => $this->client()->products()->listProducts([
                    'page' => $page,
                    'include' => 'variants',
                ])
            )
        );

        return array_merge(...array_map(
            fn (array $page) => YouCanProductDTO::fromList($page),
            $pages
        ));
    }

    /**
     * List orders for this store via the YouCan API, fetching every page.
     *
     * Requests the customer, variants, payment, and shipping subresources
     * so each order comes back with full customer details and line items
     * rather than requiring a separate lookup per order.
     *
     * YouCan's `/orders` endpoint doesn't reliably nest a `customer.address`
     * even with `include=customer` — real responses only carry flat
     * customer_id/customer_name/customer_phone fields and a null
     * shipping_address. So any order left without an address here is
     * backfilled from the store's customer list (fetched once, not per
     * order), which does carry each customer's saved address(es).
     *
     * @return YouCanOrderDTO[]
     */
    public function loadOrders(?CarbonInterface $since = null): array
    {
        // `filters[n][field]=created_at_from` with a Y-m-d value, per the
        // YouCan Store Admin API's List Orders parameters. Day-granular, so
        // it can over-return within the cutoff day — OrderSyncService's own
        // check trims that remainder to the exact timestamp.
        $filters = $since === null ? [] : [
            'filters' => [
                ['field' => 'created_at_from', 'value' => $since->toDateString()],
            ],
        ];

        $pages = $this->fetchAllPages(
            fn (int $page) => $this->execute(
                fn () => $this->client()->orders()->listOrders([
                    'page' => $page,
                    'include' => 'customer,variants,payment,shipping',
                    ...$filters,
                ])
            )
        );

        $orders = array_merge(...array_map(
            fn (array $page) => YouCanOrderDTO::fromList($page),
            $pages
        ));

        return $this->backfillShippingAddresses($orders);
    }

    /**
     * Subscribe this store to YouCan's order.create REST Hook. Failure is
     * logged rather than thrown — a store must still be usable (via the
     * nightly reconciliation sync) even if this subscription fails, per
     * UC-4.
     *
     * YouCan caps a store at 3 REST Hook subscriptions total, 1 per event —
     * re-subscribing to order.create without clearing the old one first
     * would either fail outright or leave a stale/duplicate hook eating
     * that quota. So any existing order.create subscription(s) are torn
     * down first, then a fresh one is registered.
     *
     * The returned subscription id is persisted to meta so
     * deregisterOrderWebhook() can unsubscribe by id later — YouCan's
     * unsubscribe endpoint requires it, and it isn't derivable any other
     * way once the subscription exists.
     */
    public function registerOrderWebhook(): void
    {
        $this->deregisterAllOrderWebhooks();

        try {
            $subscription = $this->client()->webhooks()->subscribe('order.create', route('webhooks.youcan.create-order', $this->store));

            $this->store->update([
                'meta' => [...($this->store->meta ?? []), 'youcan_resthook_id' => $subscription['id'] ?? null],
            ]);
        } catch (Throwable $e) {
            Log::warning('YouCan webhook subscription failed', ['store_id' => $this->store->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Unsubscribe this store from YouCan's order.create REST Hook, e.g.
     * when the store is being disconnected/deleted. No-ops if no
     * subscription id was ever persisted (subscription never succeeded, or
     * the store predates this being tracked) — nothing to unsubscribe from.
     */
    public function deregisterOrderWebhook(): void
    {
        $subscriptionId = $this->store->meta['youcan_resthook_id'] ?? null;

        if ($subscriptionId === null) {
            return;
        }

        try {
            $this->client()->webhooks()->unsubscribe($subscriptionId);
        } catch (Throwable $e) {
            Log::warning('YouCan webhook unsubscription failed', ['store_id' => $this->store->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Unsubscribe this store from every order.create REST Hook subscription
     * currently registered with YouCan, rather than relying on the single
     * id persisted to meta. Useful when that id is stale/missing or extra
     * subscriptions were created out of band. No-ops if none are found.
     */
    public function deregisterAllOrderWebhooks(): void
    {
        try {
            $subscriptions = $this->client()->webhooks()->list();

            $orderCreateSubscriptions = array_filter(
                $subscriptions['data'] ?? $subscriptions,
                fn (array $subscription) => ($subscription['event'] ?? null) === 'order.create'
            );
        } catch (Throwable $e) {
            Log::warning('YouCan webhook list failed', ['store_id' => $this->store->id, 'error' => $e->getMessage()]);

            return;
        }

        foreach ($orderCreateSubscriptions as $subscription) {
            try {
                $this->client()->webhooks()->unsubscribe($subscription['id']);
            } catch (Throwable $e) {
                Log::warning('YouCan webhook unsubscription failed', ['store_id' => $this->store->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Fill in missing shipping addresses on a set of orders from this
     * store's customer list, keyed by customer id. One paginated fetch
     * covers every order, instead of a per-order customer lookup.
     *
     * @param  YouCanOrderDTO[]  $orders
     * @return YouCanOrderDTO[]
     */
    private function backfillShippingAddresses(array $orders): array
    {
        $needsBackfill = array_filter(
            $orders,
            fn ($order) => $order->shippingAddress === null && $order->customerId !== null
        );

        if ($needsBackfill === []) {
            return $orders;
        }

        $addressesByCustomerId = [];

        foreach ($this->loadCustomers() as $customer) {
            $addressesByCustomerId[$customer->id] = $customer->addresses[0] ?? $this->addressFromCustomerFields($customer);
        }

        return array_map(
            fn (YouCanOrderDTO $order) => $order->withShippingAddress(
                $addressesByCustomerId[$order->customerId] ?? null
            ),
            $orders
        );
    }

    /**
     * Build a fallback address from a customer's flat city/region/country
     * fields, for customers with no saved address entry — YouCan lets a
     * COD customer have just a city on file, with an empty `address` list.
     */
    private function addressFromCustomerFields(YouCanCustomerDTO $customer): ?YouCanAddressDTO
    {
        if (! $customer->city && ! $customer->region && ! $customer->country) {
            return null;
        }

        return YouCanAddressDTO::fromArray([
            'first_name' => $customer->firstName,
            'last_name' => $customer->lastName,
            'city' => $customer->city,
            'region' => $customer->region,
            'country_code' => $customer->country,
            'phone' => $customer->phone,
        ]);
    }

    /**
     * Get a single order, including its customer, line items, payment, and
     * shipping details.
     */
    public function getOrder(string $orderId): YouCanOrderDTO
    {
        return $this->execute(fn () => YouCanOrderDTO::fromArray(
            $this->client()->orders()->getOrderWithCustomer($orderId)
        ));
    }

    /**
     * List customers for this store via the YouCan API, fetching every page.
     *
     * @return YouCanCustomerDTO[]
     */
    public function loadCustomers(): array
    {
        $pages = $this->fetchAllPages(
            fn (int $page) => $this->execute(
                fn () => $this->client()->customers()->listCustomers([
                    'page' => $page,
                    'include' => 'address',
                ])
            )
        );

        return array_merge(...array_map(
            fn (array $page) => YouCanCustomerDTO::fromList($page),
            $pages
        ));
    }

    /**
     * Get a single customer together with their address(es) and full order
     * history.
     */
    public function getCustomerWithOrders(string $customerId): YouCanCustomerDTO
    {
        return $this->execute(fn () => YouCanCustomerDTO::fromArray(
            $this->client()->customers()->getCustomerWithOrders($customerId)
        ));
    }

    /**
     * Fetch every page of a YouCan paginated list endpoint.
     *
     * YouCan wraps list responses as:
     *   { "data": [...], "meta": { "pagination": { "current_page", "total_pages", ... } } }
     *
     * @param  callable(int): array<int|string, mixed>  $fetchPage  Called with the 1-indexed page number.
     * @return array<int, array<int|string, mixed>> The raw response body for each page fetched.
     */
    private function fetchAllPages(callable $fetchPage): array
    {
        $pages = [];
        $page = 1;

        do {
            $response = $fetchPage($page);
            $pages[] = $response;

            $pagination = $response['meta']['pagination'] ?? null;
            $page++;
        } while ($pagination && $page <= ($pagination['total_pages'] ?? 1));

        return $pages;
    }
}
