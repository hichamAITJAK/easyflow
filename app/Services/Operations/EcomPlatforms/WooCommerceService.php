<?php

namespace App\Services\Operations\EcomPlatforms;

use App\DTOs\WooCommerce\WooCommerceOrderDTO;
use App\DTOs\WooCommerce\WooCommerceProductDTO;
use App\Interfaces\EcomPlatformInterface;
use App\Models\Store;
use App\Services\Connectivity\WooCommerce\WooCommerceClient;
use App\Services\Connectivity\WooCommerce\WooCommerceHttpClient;
use App\Services\Connectivity\WooCommerce\WooCommerceWebhookService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * WooCommerce integration.
 *
 * WooCommerce differs from the other platforms in ways that shape this
 * class:
 *
 *  1. **Self-hosted.** Every merchant runs their own WordPress install at
 *     their own domain, so the API host is per-store rather than a fixed
 *     vendor endpoint. That host is merchant-supplied input and is vetted
 *     by WooCommerceStoreUrl before any request is made against it.
 *  2. **No OAuth.** The merchant generates a consumer key/secret pair in
 *     WooCommerce > Settings > Advanced > REST API and pastes it in, so
 *     there is no authorization redirect, no code exchange and no refresh
 *     token — hence no execute()/refreshToken() retry bridge.
 *  3. **Variable products need a second call.** A `type: variable`
 *     product's sellable rows (their SKUs, prices and stock) live on
 *     /products/{id}/variations, not on the product itself.
 *  4. **Real HMAC webhooks.** Unlike Storeep, WooCommerce signs deliveries,
 *     so the payload can be trusted once verified and does not need
 *     re-fetching.
 *
 * Docs: https://woocommerce.github.io/woocommerce-rest-api-docs/
 */
class WooCommerceService implements EcomPlatformInterface
{
    /**
     * WooCommerce caps per_page at 100 on every list endpoint.
     */
    private const PAGE_SIZE = WooCommerceHttpClient::MAX_PER_PAGE;

    /**
     * Upper bound on pages walked per list endpoint.
     *
     * A large catalog on slow shared hosting can otherwise keep a queue
     * worker busy indefinitely. At 100 rows per page this admits 50,000
     * rows, well beyond any store we expect, and the cap is logged when hit
     * rather than silently truncating the sync.
     */
    private const MAX_PAGES = 500;

    public function __construct(private readonly ?Store $store = null) {}

    // ----------------------------------------------------------------
    // Prepare Connectivity Bridge
    // ----------------------------------------------------------------

    /**
     * Build an authenticated WooCommerce client from this store's stored
     * credentials.
     */
    private function client(): WooCommerceClient
    {
        $credentials = $this->credentials();

        return new WooCommerceClient(
            $credentials['store_url'] ?? '',
            $credentials['consumer_key'] ?? '',
            $credentials['consumer_secret'] ?? '',
        );
    }

    /**
     * Read this store's WooCommerce credentials out of its encrypted blob.
     *
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        return json_decode((string) $this->store?->api_credentials, true) ?? [];
    }

    // ----------------------------------------------------------------
    // Implementation Of EcomPlatformInterface Interface
    // ----------------------------------------------------------------

    /**
     * Read the store's own details from its system status endpoint.
     *
     * Falls back to what we already hold if the call fails — this is
     * informational, and a store must stay usable when its WordPress is
     * briefly unreachable.
     *
     * @return array<string, mixed>
     */
    public function getStoreDetails(): array
    {
        $fallback = [
            'id' => $this->store?->external_store_id,
            'name' => $this->store?->name,
            'domain' => $this->store?->domain,
        ];

        try {
            $environment = $this->client()->system()->status()['environment'] ?? [];
        } catch (Throwable $e) {
            Log::warning('WooCommerce store details fetch failed', [
                'store_id' => $this->store?->id,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }

        return [
            'id' => $this->store?->external_store_id,
            'name' => $environment['site_title'] ?? $fallback['name'],
            'domain' => $environment['site_url'] ?? $fallback['domain'],
            'woocommerce_version' => $environment['version'] ?? null,
            'wordpress_version' => $environment['wp_version'] ?? null,
            'currency' => $environment['currency'] ?? null,
        ];
    }

    /**
     * List this store's products, fetching every page.
     *
     * Each `type: variable` product costs one extra request to read its
     * variations, since WooCommerce keeps the sellable rows (with their own
     * SKUs, prices and stock) on a separate endpoint and puts only their IDs
     * on the parent. Simple products need no second call.
     *
     * @return WooCommerceProductDTO[]
     */
    public function loadProducts(): array
    {
        $rows = $this->fetchAllPages(
            fn (int $page) => $this->client()->products()->listProducts([
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'status' => 'any',
                'orderby' => 'id',
                'order' => 'asc',
            ]),
            'products'
        );

        return array_map(
            fn (array $product) => WooCommerceProductDTO::fromArray(
                $product,
                ($product['type'] ?? null) === 'variable'
                    ? $this->loadVariations($product['id'] ?? null)
                    : []
            ),
            $rows
        );
    }

    /**
     * Fetch every variation of a variable product.
     *
     * A failure here is logged and degraded to an empty list rather than
     * thrown: one product whose variations can't be read must not fail the
     * whole catalog sync. The product still syncs, with the single row
     * synthesized from the parent.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadVariations(int|string|null $productId): array
    {
        if ($productId === null || $productId === '') {
            return [];
        }

        try {
            return $this->fetchAllPages(
                fn (int $page) => $this->client()->products()->listVariations($productId, [
                    'page' => $page,
                    'per_page' => self::PAGE_SIZE,
                ]),
                "variations for product {$productId}"
            );
        } catch (Throwable $e) {
            Log::warning('WooCommerce product variations fetch failed', [
                'store_id' => $this->store?->id,
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * List this store's orders, fetching every page.
     *
     * `$since` is applied server-side via `after`, so a newly connected
     * store doesn't pull its whole trading history. `dates_are_gmt=true`
     * makes that boundary unambiguous — without it WooCommerce interprets
     * the date in the store's local timezone, which would silently shift the
     * cutoff by the store's UTC offset.
     *
     * `status=any` is deliberate: a COD order can legitimately sit in
     * pending, on-hold or processing depending on how the gateway is
     * configured, and filtering to one of them would silently drop orders.
     * OrderSyncService re-applies the connect-date cutoff to whatever comes
     * back, which is what actually enforces it.
     *
     * @return WooCommerceOrderDTO[]
     */
    public function loadOrders(?CarbonInterface $since = null): array
    {
        $query = [
            'per_page' => self::PAGE_SIZE,
            'status' => 'any',
            'orderby' => 'date',
            'order' => 'desc',
            'dates_are_gmt' => 'true',
        ];

        if ($since !== null) {
            $query['after'] = $since->copy()->utc()->toIso8601String();
        }

        $rows = $this->fetchAllPages(
            fn (int $page) => $this->client()->orders()->listOrders([...$query, 'page' => $page]),
            'orders'
        );

        return WooCommerceOrderDTO::fromList($rows);
    }

    /**
     * Fetch a single order by its WooCommerce id.
     */
    public function getOrder(string $orderId): ?WooCommerceOrderDTO
    {
        $order = $this->client()->orders()->getOrder($orderId);

        return ($order['id'] ?? null) === null ? null : WooCommerceOrderDTO::fromArray($order);
    }

    /**
     * Subscribe this store to WooCommerce's order webhooks. Failure is
     * logged rather than thrown — a store must still be usable (via the
     * nightly reconciliation sync) even if this subscription fails, per
     * UC-4.
     *
     * Both `order.created` and `order.updated` are registered. Unlike the
     * other platforms, WooCommerce's created event fires when the order row
     * is first written, which for some payment flows happens before the
     * customer's details are final; the updated event is what carries the
     * settled order. Registering both means the order arrives promptly and
     * is then corrected in place, since OrderSyncService upserts on
     * (store_id, external_order_id).
     *
     * WooCommerce signs deliveries with an HMAC-SHA256 of the raw body
     * keyed on the webhook secret, so unlike Storeep the delivered payload
     * can be trusted once verified and does not need re-fetching. The
     * secret is generated here and persisted to stores.webhook_secret.
     *
     * Any previous EasyFlow subscription is torn down first, so repeated
     * reconnections don't leave duplicates behind delivering the same order
     * twice.
     */
    public function registerOrderWebhook(): void
    {
        $this->deregisterAllOrderWebhooks();

        $secret = $this->store->webhook_secret ?: Str::random(64);

        $topics = [
            WooCommerceWebhookService::TOPIC_ORDER_CREATED,
            WooCommerceWebhookService::TOPIC_ORDER_UPDATED,
        ];

        $registered = [];

        foreach ($topics as $topic) {
            try {
                $response = $this->client()->webhooks()->subscribe(
                    topic: $topic,
                    deliveryUrl: route('webhooks.woocommerce.create-order', ['store' => $this->store]),
                    secret: $secret,
                    name: "EasyFlow {$topic}",
                );

                if (isset($response['id'])) {
                    $registered[] = $response['id'];
                }
            } catch (Throwable $e) {
                Log::warning('WooCommerce webhook subscription failed', [
                    'store_id' => $this->store->id,
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // The secret is persisted even when every subscription failed: it is
        // what the receiving controller verifies against, and a later manual
        // retry must be able to reuse it.
        $this->store->update([
            'webhook_secret' => $secret,
            'meta' => [
                ...($this->store->meta ?? []),
                'woocommerce_webhook_ids' => $registered,
            ],
        ]);
    }

    /**
     * Unsubscribe this store from its WooCommerce order webhooks, e.g. when
     * the store is being disconnected/deleted. No-ops if no subscription
     * ids were ever persisted.
     */
    public function deregisterOrderWebhook(): void
    {
        $webhookIds = $this->store->meta['woocommerce_webhook_ids'] ?? [];

        if ($webhookIds === []) {
            return;
        }

        foreach ((array) $webhookIds as $webhookId) {
            try {
                $this->client()->webhooks()->unsubscribe((string) $webhookId);
            } catch (Throwable $e) {
                Log::warning('WooCommerce webhook unsubscription failed', [
                    'store_id' => $this->store->id,
                    'webhook_id' => $webhookId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Delete every order webhook currently registered for this store that
     * points back at EasyFlow, rather than relying on the ids persisted to
     * meta — those can be stale or missing if a previous subscription
     * attempt half-succeeded. No-ops if none are found.
     *
     * Only our own webhooks are touched: a merchant may well have their own
     * order webhooks pointing elsewhere, and deleting those would silently
     * break their integration.
     */
    public function deregisterAllOrderWebhooks(): void
    {
        try {
            $webhooks = $this->fetchAllPages(
                fn (int $page) => $this->client()->webhooks()->list([
                    'page' => $page,
                    'per_page' => self::PAGE_SIZE,
                    'status' => 'all',
                ]),
                'webhooks'
            );
        } catch (Throwable $e) {
            Log::warning('WooCommerce webhook list failed', [
                'store_id' => $this->store->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $ours = array_filter(
            $webhooks,
            fn (array $webhook) => str_contains((string) ($webhook['delivery_url'] ?? ''), '/webhooks/woocommerce/')
        );

        foreach ($ours as $webhook) {
            try {
                $this->client()->webhooks()->unsubscribe((string) $webhook['id']);
            } catch (Throwable $e) {
                Log::warning('WooCommerce webhook unsubscription failed', [
                    'store_id' => $this->store->id,
                    'webhook_id' => $webhook['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Walk every page of a WooCommerce list endpoint and return the rows.
     *
     * WordPress reports pagination in the `X-WP-TotalPages` response header
     * rather than in the body, which WooCommerceHttpClient::getPage()
     * surfaces alongside the rows. Walking to that count (rather than until
     * a short page arrives) is what makes this correct: a page can come back
     * short when rows are filtered out by permissions, which would end the
     * walk early.
     *
     * @param  callable(int): array{items: array<int, array<string, mixed>>, totalPages: int}  $fetchPage
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllPages(callable $fetchPage, string $what): array
    {
        $rows = [];
        $page = 1;

        do {
            $response = $fetchPage($page);
            $rows = [...$rows, ...$response['items']];

            $totalPages = $response['totalPages'];
            $page++;
        } while ($page <= $totalPages && $page <= self::MAX_PAGES);

        if ($totalPages > self::MAX_PAGES) {
            Log::warning('WooCommerce list walk hit the page cap; results are truncated.', [
                'store_id' => $this->store?->id,
                'endpoint' => $what,
                'total_pages' => $totalPages,
                'pages_fetched' => self::MAX_PAGES,
            ]);
        }

        return $rows;
    }
}
