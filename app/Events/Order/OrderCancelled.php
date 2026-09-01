<?php

namespace App\Events\Order;

use App\Enums\OrderCancelReason;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when confirmation_status transitions to CANCELLED (UC-9), in
 * addition to the generic ConfirmationStatusChanged. Carries the
 * structured reason code required by every cancellation — never fired
 * without one.
 */
class OrderCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderCancelReason $reasonCode,
    ) {}
}
