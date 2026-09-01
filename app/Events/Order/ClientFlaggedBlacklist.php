<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an order's client phone matches an existing
 * CustomerBlacklistEntry (UC-10) and the order is flagged
 * is_blacklist_flagged. The order-side effect is already applied by the
 * listener that fires this — this event exists so other concerns (e.g. a
 * notification to the owner) can hook in without touching blacklist
 * detection logic itself.
 */
class ClientFlaggedBlacklist
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
