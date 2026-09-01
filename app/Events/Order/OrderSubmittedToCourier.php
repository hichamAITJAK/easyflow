<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when confirmation_status transitions to SUBMITTED_TO_COURIER
 * (UC-12: parcel successfully created at the delivery courier), in
 * addition to the generic ConfirmationStatusChanged — the terminal state
 * for the confirmation side of an order's lifecycle.
 */
class OrderSubmittedToCourier
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
