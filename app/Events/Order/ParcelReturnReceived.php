<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a fulfillment agent scans a returned parcel back into the
 * warehouse (UC-17), setting delivery_status = return_received and
 * orders.return_received_at. This is the warehouse-confirmed physical
 * fact — the ONLY event blacklist scoring and stock-restocking listeners
 * may key off (never OrderReturnedInTransit, which is only the courier's
 * claim and can still be reversed or wrong).
 *
 * Dispatched by OrderService::updateDeliveryStatus() (the mobile
 * fulfillment scan API is its only caller for this transition).
 * Blacklist-scoring and stock-restocking listeners keyed off this event
 * are not built yet — neither feature exists in the codebase at all yet,
 * independent of this event's wiring — this docblock is the seam for
 * whichever gets built first.
 */
class ParcelReturnReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
