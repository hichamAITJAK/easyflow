<?php

namespace App\Events\Order;

use App\Enums\OrderReturnReason;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when delivery_status transitions to RETURNED_IN_TRANSIT (UC-13),
 * in addition to the generic DeliveryStatusChanged. This is the courier's
 * claim only — the parcel is still physically with the courier, not the
 * warehouse. Blacklist scoring and stock-restocking must NOT key off this
 * event; they key off ParcelReturnReceived (the fulfillment agent's
 * physical scan), which is the confirmed fact. This event exists for
 * listeners with a lower confidence bar, e.g. an early "a return may be
 * coming" notification to the owner/manager.
 */
class OrderReturnedInTransit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderReturnReason $reasonCode,
    ) {}
}
