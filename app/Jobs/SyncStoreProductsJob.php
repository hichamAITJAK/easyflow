<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Operations\Products\ProductSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Syncs a store's products in the background. Dispatched by the order
 * webhook jobs when an order arrives for a store whose products haven't
 * been synced yet, so the order can be retried against a populated
 * products table instead of landing with unmatched line items.
 */
class SyncStoreProductsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Store $store) {}

    public function handle(ProductSyncService $productSync): void
    {
        $productSync->syncStore($this->store);
    }
}
