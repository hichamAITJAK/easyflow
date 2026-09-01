<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when parcel creation at the delivery courier fails (UC-12): the
 * courier's API rejected the request or errored, dispatched from
 * OrderService::createShipment()'s catch block. The order must be flagged
 * for manual delivery creation here — never silently stuck in a
 * confirmed-but-unshipped state.
 */
class OrderDeliveryCreationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly string $reason,
    ) {}
}
