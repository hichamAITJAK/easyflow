<?php

namespace App\Events\Store;

use App\Models\Store;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired immediately before a store is deleted, while its credentials are
 * still intact. StoreController::destroy() dispatches this synchronously
 * ahead of $store->delete() — any listener that needs to make one last
 * authenticated call against the platform (e.g. unsubscribing a webhook)
 * must run to completion here, since the store row (and its
 * api_credentials/meta) is gone the moment delete() returns.
 */
class StoreDeleting
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Store $store) {}
}
