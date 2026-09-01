<?php

namespace App\Events\Order;

use App\Enums\OrderConfirmationStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired unconditionally on every confirmation_status transition — the
 * generic audit-log/stats hook (PRD section 7.3). Specific events
 * (OrderConfirmed, OrderCancelled, OrderSubmittedToCourier, ...) fire in
 * addition to this one, for statuses with dedicated business logic; this
 * event exists so listeners that care about every transition (the audit
 * log, live stats counters) don't need to subscribe to every specific one.
 *
 * $actor is null for system-driven transitions (e.g. auto-cancel after max
 * no-answer attempts) — the audit log listener must handle that.
 */
class ConfirmationStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderConfirmationStatus $fromStatus,
        public readonly OrderConfirmationStatus $toStatus,
        public readonly ?User $actor,
    ) {}
}
