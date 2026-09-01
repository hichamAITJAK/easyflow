<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a fulfillment agent scans a parcel as physically staged and
 * ready for courier pickup (UC-17), setting delivery_status =
 * ready_for_pickup and orders.ready_for_pickup_at. Distinct from
 * awaiting_pickup (which only means the parcel was registered at the
 * courier) — this is the warehouse-side physical confirmation.
 *
 * Dispatched by OrderService::updateDeliveryStatus() (the mobile
 * fulfillment scan API is its only caller for this transition). The
 * listener it feeds is an internal-only notification to the owner/manager
 * (UC-18), distinct from OrderReturnedInTransit's/OrderDelivered's
 * customer-facing notices — actual push delivery is a separate,
 * not-yet-built piece (no Expo/device-token infra exists yet).
 */
class ParcelReadyForPickup
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
