<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when delivery_status transitions to DELIVERED (UC-13: client
 * received and paid), in addition to the generic DeliveryStatusChanged.
 *
 * No commission side effect here: CalculateAgentCommission already records
 * the agent's commission at OrderConfirmed time, and commission_ledger_entries
 * has no pending/provisional state to finalize — the entry created there is
 * already final. A future listener on OrderReturnedInTransit or
 * ParcelReturnReceived is the right seam for clawing back commission via a
 * reversal entry, if a delivered-then-returned order ever needs that.
 */
class OrderDelivered
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
