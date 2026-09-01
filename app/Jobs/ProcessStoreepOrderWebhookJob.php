<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Store;
use App\Services\Operations\EcomPlatforms\StoreepService;
use App\Services\Operations\Orders\OrderSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates/updates an Order from a Storeep order webhook delivery.
 *
 * Unlike the Shopify and YouCan jobs, this one does NOT build the order
 * from the delivered payload. Storeep publishes no webhook signature, so
 * the delivered body is unauthenticated data — the URL secret checked by
 * StoreepWebhookController proves only that the caller knew the URL. So
 * the payload is used for exactly one thing (reading the order id) and the
 * order itself is re-fetched from Storeep's API, where the access token
 * authenticates the response.
 *
 * The cost is one extra API call per webhook, against a 60 req/min budget.
 *
 * As with the other platforms' webhook jobs, a delivery can arrive before
 * the store's products have ever been synced (webhook registration and the
 * initial product sync aren't ordered or atomic) — in that case this job
 * kicks off a product sync and releases itself back onto the queue with a
 * delay, so the retry lands with products already in place instead of
 * creating an order with unmatched line items.
 */
class ProcessStoreepOrderWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * How long to wait before retrying once a product sync has been
     * kicked off for the store, and how many times to do so before giving
     * up and processing the order anyway with whatever products exist.
     */
    private const PRODUCT_SYNC_RETRY_DELAY_SECONDS = 300;

    private const MAX_PRODUCT_SYNC_RETRIES = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly Store $store,
        public readonly array $payload,
    ) {}

    public function handle(OrderSyncService $orderSync): void
    {
        if (! Product::where('store_id', $this->store->id)->exists()
            && $this->attempts() <= self::MAX_PRODUCT_SYNC_RETRIES) {
            SyncStoreProductsJob::dispatch($this->store);

            $this->release(self::PRODUCT_SYNC_RETRY_DELAY_SECONDS);

            return;
        }

        $orderId = $this->orderId();

        if ($orderId === null) {
            Log::warning('Storeep order webhook carried no order id.', [
                'store_id' => $this->store->id,
            ]);

            return;
        }

        try {
            $order = (new StoreepService($this->store))->getOrder($orderId);
        } catch (Throwable $e) {
            Log::error('Failed to re-fetch Storeep order for webhook.', [
                'store_id' => $this->store->id,
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
            ]);

            // Rethrow so the queue's own retry/backoff handles it: unlike a
            // malformed payload, a failed fetch is usually transient (rate
            // limit, timeout) and the order is real and still missing.
            throw $e;
        }

        if ($order === null) {
            Log::warning('Storeep order from webhook was not found in the orders list.', [
                'store_id' => $this->store->id,
                'order_id' => $orderId,
            ]);

            return;
        }

        $orderSync->syncOne($this->store, $order->toArray());
    }

    /**
     * Pull the order id out of the delivered payload.
     *
     * This is the only thing the unauthenticated body is trusted for, and
     * it is used solely as a lookup key against the API. Storeep's webhook
     * payload shape isn't documented in detail, so the order object is
     * accepted either at the top level or nested under `order`/`data`.
     */
    private function orderId(): ?string
    {
        $order = $this->payload['order'] ?? $this->payload['data'] ?? $this->payload;

        $id = $order['id'] ?? null;

        return ($id === null || $id === '') ? null : (string) $id;
    }
}
