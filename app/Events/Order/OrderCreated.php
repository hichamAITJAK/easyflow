<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once an order row exists — manual creation or store sync alike
 * (only for a store-synced order's first creation, never on a re-sync
 * update). Listeners that must not run for test orders (blacklist,
 * duplicate check, commission, delivery dispatch) are responsible for
 * checking order.is_test themselves — this event fires unconditionally,
 * per the PRD's defense-in-depth rule for the test-order carve-out.
 */
class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
