<?php

namespace App\Jobs;

use App\DTOs\WooCommerce\WooCommerceOrderDTO;
use App\Models\Product;
use App\Models\Store;
use App\Services\Operations\Orders\OrderSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates/updates an Order from a single WooCommerce order webhook payload.
 *
 * WooCommerce signs deliveries with an HMAC-SHA256 of the raw body (checked
 * by WooCommerceWebhookController), and its payload is byte-for-byte the
 * same representation the REST API returns for that order. So unlike the
 * Storeep job, this one builds the order straight from the delivered body
 * — there is nothing a re-fetch would add, and it would cost an extra
 * round-trip to the merchant's own WordPress.
 *
 * A webhook can arrive before the store's products have ever been synced
 * (webhook registration and the initial product sync aren't ordered or
 * atomic) — in that case this job kicks off a product sync and releases
 * itself back onto the queue with a delay, so the retry lands with products
 * already in place instead of creating an order with unmatched line items.
 */
class ProcessWooCommerceOrderWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * How long to wait before retrying once a product sync has been kicked
     * off for the store, and how many times to do so before giving up and
     * processing the order anyway with whatever products exist.
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

        try {
            $dto = WooCommerceOrderDTO::fromArray($this->payload);
        } catch (Throwable $e) {
            Log::error('Failed to parse WooCommerce order webhook payload.', [
                'store_id' => $this->store->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        if ($dto->id === '') {
            Log::warning('WooCommerce order webhook carried no order id.', [
                'store_id' => $this->store->id,
            ]);

            return;
        }

        $orderSync->syncOne($this->store, $dto->toArray());
    }
}
