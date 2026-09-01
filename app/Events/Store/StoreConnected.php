<?php

namespace App\Events\Store;

use App\Models\Store;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once a store's row exists with connection_status = CONNECTED,
 * regardless of platform (Shopify, YouCan, Lightfunnels, ...). Every
 * connection controller fires this after creating the store — platform-
 * specific steps (e.g. webhook subscription) stay inline in the
 * controller, since they differ per provider, but anything that should
 * happen identically for every newly connected store belongs in a
 * listener on this event instead of being duplicated across controllers.
 */
class StoreConnected
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Store $store) {}
}
