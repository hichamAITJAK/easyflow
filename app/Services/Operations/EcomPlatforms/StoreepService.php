<?php

namespace App\Services\Operations\EcomPlatforms;

use App\DTOs\Storeep\StoreepOrderDTO;
use App\DTOs\Storeep\StoreepProductDTO;
use App\Interfaces\EcomPlatformInterface;
use App\Models\Store;
use App\Services\Connectivity\Storeep\StoreepClient;
use App\Services\Connectivity\Storeep\StoreepWebhookService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Storeep integration.
 *
 * Storeep differs from the other platforms in three ways that shape this
 * class:
 *
 *  1. No OAuth. The merchant creates an access token (a UUID) in their
 *     Storeep dashboard and pastes it into EasyFlow, so there is no
 *     authorization redirect, no code exchange, and no refresh token —
 *     hence no execute()/refreshToken() retry bridge.
 *  2. No store details endpoint. The store's name is supplied by the
 *     merchant at connect time rather than read back from the API.
 *  3. No documented webhook signature. See registerOrderWebhook() for how
 *     inbound deliveries are authenticated instead.
 *
 * Docs: https://docs.storeep.com
 */
class StoreepService implements EcomPlatformInterface
{
    /**
     * Storeep's maximum page size for both /products and /orders.
     */
    private const PAGE_SIZE = 50;

    public function __construct(private readonly ?Store $store = null) {}

    // ----------------------------------------------------------------
    // Prepare Connectivity Bridge
    // ----------------------------------------------------------------

    /**
     * Build an authenticated Storeep client from this store's stored
     * credentials.
     */
    private function client(): StoreepClient
    {
        return new StoreepClient($this->accessToken());
    }

    /**
     * Read this store's Storeep access token out of its encrypted
     * credentials blob.
     */
    private function accessToken(): string
    {
        $credentials = json_decode((string) $this->store->api_credentials, true) ?? [];

        return $credentials['access_token'] ?? '';
    }

    /**
     * The market (2-letter country code) this store sells in, if it was
     * recorded at connect time. Used to pick the right row out of a
     * product's per-market pricing list.
     */
    private function preferredMarket(): ?string
    {
        return $this->store?->meta['market'] ?? null;
    }

    // ----------------------------------------------------------------
    // Implementation Of EcomPlatformInterface Interface
    // ----------------------------------------------------------------

    /**
     * Storeep publishes no store-details endpoint — the merchant names the
     * store when pasting their access token, and that name is persisted at
     * connect time. Returns what we already hold rather than pretending to
     * fetch it.
     *
     * @return array<string, mixed>
     */
    public function getStoreDetails(): array
    {
        return [
            'id' => $this->store?->external_store_id,
            'name' => $this->store?->name,
            'domain' => $this->store?->domain,
        ];
    }

    /**
     * List products for this store via the Storeep API, fetching every page.
     *
     * @return StoreepProductDTO[]
     */
    public function loadProducts(): array
    {
        $pages = $this->fetchAllPages(
            fn (int $page) => $this->client()->products()->listProducts([
                'page' => $page,
                'limit' => self::PAGE_SIZE,
            ])
        );

        $market = $this->preferredMarket();

        return array_merge(...array_map(
            fn (array $page) => StoreepProductDTO::fromList($page, $market),
            $pages
        ));
    }

    /**
     * List orders for this store via the Storeep API, fetching every page.
     *
     * `$since` is accepted to satisfy the interface but cannot be applied:
     * Storeep's `/orders` endpoint exposes no date filter of any kind. The
     * only server-side scoping available is the access token's own data
     * access window, which the merchant chooses when creating the token
     * (last 24 hours / a set start date / all time) and which we can neither
     * read nor set through the API.
     *
     * The connect-date cutoff is therefore enforced entirely on our side by
     * OrderSyncService::rejectOrdersBefore(), exactly as it is for
     * Lightfunnels. Orders are pulled newest-first so the pages that matter
     * arrive before the historical tail.
     *
     * @return StoreepOrderDTO[]
     */
    public function loadOrders(?CarbonInterface $since = null): array
    {
        $pages = $this->fetchAllPages(
            fn (int $page) => $this->client()->orders()->listOrders([
                'page' => $page,
                'limit' => self::PAGE_SIZE,
                'sort_field' => 'created_at',
                'sort_order' => 'DESC',
            ])
        );

        return array_merge(...array_map(
            fn (array $page) => StoreepOrderDTO::fromList($page),
            $pages
        ));
    }

    /**
     * Subscribe this store to Storeep's order-created webhook. Failure is
     * logged rather than thrown — a store must still be usable (via the
     * nightly reconciliation sync) even if this subscription fails, per
     * UC-4.
     *
     * Storeep documents no signing secret and no signature header, so an
     * inbound delivery cannot be verified the way Shopify's and YouCan's
     * HMAC-signed deliveries are. Two things stand in for that:
     *
     *  - The receiving URL embeds a per-store random secret (persisted to
     *    stores.webhook_secret), so the endpoint is unguessable and the
     *    controller can reject anything that doesn't match it.
     *  - The job ignores the delivered body entirely and re-fetches the
     *    order from the API (see ProcessStoreepOrderWebhookJob), so even a
     *    leaked URL can only trigger a fetch of real data — never inject a
     *    forged order.
     *
     * Storeep caps a store at 20 webhooks. Any previous EasyFlow
     * order-created subscription is torn down first so repeated
     * reconnections can't consume that quota.
     */
    public function registerOrderWebhook(): void
    {
        $this->deregisterAllOrderWebhooks();

        $secret = $this->store->webhook_secret ?: Str::random(64);

        try {
            $response = $this->client()->webhooks()->subscribe(
                event: StoreepWebhookService::EVENT_ORDER_CREATED,
                urlOrEmail: route('webhooks.storeep.create-order', [
                    'store' => $this->store,
                    'secret' => $secret,
                ]),
                name: 'EasyFlow orders',
            );

            $this->store->update([
                'webhook_secret' => $secret,
                'meta' => [
                    ...($this->store->meta ?? []),
                    'storeep_webhook_id' => $response['data']['id'] ?? null,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('Storeep webhook subscription failed', [
                'store_id' => $this->store->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Unsubscribe this store from its Storeep order-created webhook, e.g.
     * when the store is being disconnected/deleted. No-ops if no
     * subscription id was ever persisted — nothing to unsubscribe from.
     */
    public function deregisterOrderWebhook(): void
    {
        $webhookId = $this->store->meta['storeep_webhook_id'] ?? null;

        if ($webhookId === null) {
            return;
        }

        try {
            $this->client()->webhooks()->unsubscribe((string) $webhookId);
        } catch (Throwable $e) {
            Log::warning('Storeep webhook unsubscription failed', [
                'store_id' => $this->store->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete every order-created webhook currently registered for this
     * store that points back at EasyFlow, rather than relying on the single
     * id persisted to meta — that id can be stale or missing if a previous
     * subscription attempt half-succeeded. No-ops if none are found.
     *
     * Only our own webhooks are touched: a merchant may well have their own
     * order-created webhook pointing somewhere else, and deleting it would
     * silently break their integration.
     */
    public function deregisterAllOrderWebhooks(): void
    {
        try {
            $response = $this->client()->webhooks()->list(['limit' => self::PAGE_SIZE]);

            $ours = array_filter(
                $response['data'] ?? $response,
                fn (array $webhook) => ($webhook['event'] ?? null) === StoreepWebhookService::EVENT_ORDER_CREATED
                    && str_contains((string) ($webhook['url_or_email'] ?? ''), '/webhooks/storeep/')
            );
        } catch (Throwable $e) {
            Log::warning('Storeep webhook list failed', [
                'store_id' => $this->store->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($ours as $webhook) {
            try {
                $this->client()->webhooks()->unsubscribe((string) $webhook['id']);
            } catch (Throwable $e) {
                Log::warning('Storeep webhook unsubscription failed', [
                    'store_id' => $this->store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Fetch a single order by id.
     *
     * Storeep has no per-order endpoint, so this walks the order list until
     * it finds a match. Used by the webhook job, which deliberately re-reads
     * the order from the API rather than trusting the delivered payload —
     * newest-first ordering means a just-created order is normally on the
     * first page.
     */
    public function getOrder(string $orderId): ?StoreepOrderDTO
    {
        foreach ($this->loadOrders() as $order) {
            if ($order->id === $orderId) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Fetch every page of a Storeep paginated list endpoint.
     *
     * Storeep wraps list responses as:
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
