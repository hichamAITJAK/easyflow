<?php

namespace App\Services\Operations\Settlements;

use App\Enums\OrderDeliveryStatus;
use App\Models\CourierSettlement;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use Carbon\CarbonInterface;

/**
 * Courier settlement business logic shared by every client (web, mobile).
 * Methods here take and return plain models/scalars/arrays only — never a
 * Request, never an HTTP response. Callers (controllers) are responsible
 * for input validation, authorization, and shaping the result for their
 * transport.
 */
class CourierSettlementService
{
    /**
     * Sum of total_amount for orders that reached `delivered` for the
     * given delivery account within the period — UC-15's "expected" side.
     * Attribution and the delivered date both key off delivery_account_id
     * and order_status_events.created_at (the durable audit log written by
     * LogOrderStatusEvent), not a live delivery_status snapshot: an order
     * delivered inside the period but later disputed/returned still
     * counts, since this is what the courier was expected to remit for
     * that period, not the order's current state.
     */
    public function computeExpected(int $businessId, int $deliveryAccountId, CarbonInterface $periodStart, CarbonInterface $periodEnd): float
    {
        $deliveredOrderIds = OrderStatusEvent::where('business_id', $businessId)
            ->where('to_status', OrderDeliveryStatus::DELIVERED->value)
            ->whereBetween('created_at', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
            ->pluck('order_id');

        return (float) Order::where('business_id', $businessId)
            ->where('delivery_account_id', $deliveryAccountId)
            ->whereIn('id', $deliveredOrderIds)
            ->sum('total_amount');
    }

    /**
     * Record the actual amount received from the courier for a period,
     * creating the settlement if one doesn't already exist for this
     * account/period, and updating it (recomputing expected_amount fresh)
     * if it does — a settlement is identified by
     * (delivery_account_id, period_start, period_end), never duplicated.
     */
    public function reconcile(
        int $businessId,
        int $deliveryAccountId,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        float $actualAmount,
        int $reconciledByUserId,
        ?string $notes = null,
    ): CourierSettlement {
        $expectedAmount = $this->computeExpected($businessId, $deliveryAccountId, $periodStart, $periodEnd);

        // updateOrCreate()'s array-based match can't use whereDate(), and
        // period_start/period_end are `date`-cast columns stored with a
        // midnight timestamp — a plain string-equality match against
        // toDateString() never matches the stored value, so this settlement
        // is found (or created) via an explicit whereDate() query instead.
        $settlement = CourierSettlement::where('business_id', $businessId)
            ->where('delivery_account_id', $deliveryAccountId)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->first() ?? new CourierSettlement([
                'business_id' => $businessId,
                'delivery_account_id' => $deliveryAccountId,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ]);

        $settlement->fill([
            'expected_amount' => $expectedAmount,
            'actual_amount' => $actualAmount,
            'difference_amount' => $actualAmount - $expectedAmount,
            'status' => 'reconciled',
            'reconciled_at' => now(),
            'reconciled_by' => $reconciledByUserId,
            'notes' => $notes,
        ])->save();

        return $settlement;
    }

    /**
     * Mark a settlement as disputed instead of reconciled — the actual
     * amount received didn't match expectations closely enough to accept,
     * kept open for follow-up with the courier rather than closed out.
     */
    public function dispute(CourierSettlement $settlement, ?string $notes = null): CourierSettlement
    {
        $settlement->update([
            'status' => 'disputed',
            'notes' => $notes ?? $settlement->notes,
        ]);

        return $settlement;
    }
}
