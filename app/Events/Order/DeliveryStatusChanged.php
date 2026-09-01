<?php

namespace App\Events\Order;

use App\Enums\OrderDeliveryStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired unconditionally on every delivery_status transition — the generic
 * audit-log/stats hook (PRD section 7.3), mirroring ConfirmationStatusChanged
 * on the delivery side. Specific events (OrderDelivered, OrderReturnedInTransit,
 * ParcelReadyForPickup, ...) fire in addition, for statuses with dedicated
 * business logic.
 *
 * $fromStatus is null for the very first delivery_status transition (the
 * column is nullable until confirmation_status reaches submitted_to_courier,
 * per PRD section 7.2). $actor is null for courier webhook/poll-driven
 * transitions, which have no human actor.
 */
class DeliveryStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly ?OrderDeliveryStatus $fromStatus,
        public readonly OrderDeliveryStatus $toStatus,
        public readonly ?User $actor,
    ) {}
}
