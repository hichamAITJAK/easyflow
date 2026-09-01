<?php

namespace App\Listeners\Store;

use App\Events\Store\StoreConnected;
use App\Services\Operations\Orders\OrderSyncService;
use App\Services\Operations\Products\ProductSyncService;
use App\Support\StoreConnectionHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a newly connected store's initial product/order import. Queued
 * since the connect flow's success redirect doesn't need this data yet —
 * the store shows as connected immediately and its catalog/orders fill in
 * moments later.
 */
class SyncStoreDataOnConnected implements ShouldQueue
{
    public function __construct(
        private readonly ProductSyncService $productSync,
        private readonly OrderSyncService $orderSync,
    ) {}

    public function handle(StoreConnected $event): void
    {
        $store = $event->store;

        try {
            // withoutFlagging: the merchant authorized this connection
            // seconds ago. If the platform is briefly not ready for API
            // calls yet, that is not a broken credential, and marking the
            // store failed here would ask them to reconnect something that
            // just worked. A later sync will flag it if it really is dead.
            StoreConnectionHealth::withoutFlagging(function () use ($store) {
                // Products must be synced first — order line items are
                // matched against this store's products table by external
                // product id.
                $this->productSync->syncStore($store);
                $this->orderSync->syncStore($store);
            });
        } catch (Throwable $e) {
            Log::warning('Initial store sync failed', [
                'store_id' => $store->id,
                'platform' => $store->platform?->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
