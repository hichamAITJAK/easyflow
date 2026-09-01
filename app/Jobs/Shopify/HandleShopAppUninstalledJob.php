<?php

namespace App\Jobs\Shopify;

use App\Enums\StoreConnectionStatus;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Shopify `app/uninstalled` — the merchant removed the app from their store.
 *
 * Shopify revokes the access token at this point, so every subsequent API call
 * for this store would fail with a 401. Marking the store disconnected and
 * dropping the dead credentials stops the sync jobs from retrying against a
 * revoked token and makes the state visible in the UI, so the merchant can
 * reconnect rather than wondering why orders stopped arriving.
 *
 * Personal data is not erased here — that is `shop/redact`'s job, which
 * Shopify sends 48 hours later (see HandleShopRedactJob). A merchant who
 * reinstalls within that window keeps their history.
 */
class HandleShopAppUninstalledJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?Store $store,
    ) {}

    public function handle(): void
    {
        if (! $this->store instanceof Store) {
            return;
        }

        $this->store->forceFill([
            'connection_status' => StoreConnectionStatus::FAILED,
            'api_credentials' => null,
        ])->save();

        Log::info('Shopify app uninstalled; store marked disconnected.', [
            'store_id' => $this->store->id,
            'business_id' => $this->store->business_id,
        ]);
    }
}
