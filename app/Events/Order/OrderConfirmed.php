<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when confirmation_status transitions to CONFIRMED (UC-7), in
 * addition to the generic ConfirmationStatusChanged — this is the seam for
 * business logic specific to confirmation (commission calculation,
 * eventually courier dispatch), so listeners with dedicated confirmed-only
 * behavior don't need to filter the generic event by status themselves.
 */
class OrderConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
