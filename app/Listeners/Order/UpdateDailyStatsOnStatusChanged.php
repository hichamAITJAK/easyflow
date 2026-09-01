<?php

namespace App\Listeners\Order;

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Events\Order\ConfirmationStatusChanged;
use App\Events\Order\DeliveryStatusChanged;
use App\Listeners\Order\Concerns\IncrementsDailyStats;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;

/**
 * Increments the pre-computed daily_stats_summary figures for a status
 * transition (PRD section 5: dashboards never aggregate the live orders
 * table). Queued — no screen needs this synchronously, only the dashboard
 * reads it, and that's fine to lag by one queue cycle.
 *
 * Alongside the counts, this is what writes the dashboard's money and
 * speed figures: order value at confirmation (revenue_confirmed),
 * collected value at delivery (revenue_delivered), and the duration sums
 * behind "avg time to confirm" (assigned → confirmed) and per-courier
 * "avg delivery days" (shipped → delivered).
 *
 * return_received (not returned_in_transit) is what increments
 * returned_count, per PRD section 7.2: the courier's own report is only a
 * claim, not a confirmed physical fact, so it must not drive stats.
 */
class UpdateDailyStatsOnStatusChanged implements ShouldQueue
{
    use IncrementsDailyStats;

    public function handleConfirmationStatusChanged(ConfirmationStatusChanged $event): void
    {
        $column = match ($event->toStatus) {
            OrderConfirmationStatus::ASSIGNED => 'assigned_count',
            OrderConfirmationStatus::CONFIRMED => 'confirmed_count',
            OrderConfirmationStatus::SUBMITTED_TO_COURIER => 'submitted_to_courier_count',
            OrderConfirmationStatus::CANCELLED => 'cancelled_count',
            default => null,
        };

        if ($column !== null) {
            $this->incrementDailyStat($event->order, $column);
        }

        if ($event->toStatus === OrderConfirmationStatus::CONFIRMED) {
            $this->incrementDailyStatMoney($event->order, 'revenue_confirmed', (float) $event->order->total_amount);

            $seconds = $this->secondsSinceAssigned($event->order);

            if ($seconds !== null) {
                $this->incrementDailyStatSeconds($event->order, 'confirm_seconds_total', $seconds);
            }
        }
    }

    public function handleDeliveryStatusChanged(DeliveryStatusChanged $event): void
    {
        $column = match ($event->toStatus) {
            OrderDeliveryStatus::DELIVERED => 'delivered_count',
            OrderDeliveryStatus::RETURN_RECEIVED => 'returned_count',
            OrderDeliveryStatus::REFUSED => 'refused_count',
            default => null,
        };

        if ($column !== null) {
            $this->incrementDailyStat($event->order, $column);
        }

        if ($event->toStatus === OrderDeliveryStatus::DELIVERED) {
            $this->incrementDailyStatMoney($event->order, 'revenue_delivered', (float) $event->order->total_amount);

            if ($event->order->shipped_at !== null) {
                $this->incrementDailyStatSeconds(
                    $event->order,
                    'delivery_seconds_total',
                    (int) $event->order->shipped_at->diffInSeconds(Carbon::now()),
                );
            }
        }
    }

    /**
     * Assigned → confirmed duration, from the audit log — the order row
     * carries no assigned_at column, but LogOrderStatusEvent records every
     * transition. Null when the order was never assigned (e.g. manually
     * created straight to confirmed).
     */
    private function secondsSinceAssigned(Order $order): ?int
    {
        $assignedAt = OrderStatusEvent::where('order_id', $order->id)
            ->where('to_status', OrderConfirmationStatus::ASSIGNED->value)
            ->latest('created_at')
            ->value('created_at');

        return $assignedAt !== null
            ? (int) Carbon::parse($assignedAt)->diffInSeconds(Carbon::now())
            : null;
    }
}
