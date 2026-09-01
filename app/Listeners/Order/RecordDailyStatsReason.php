<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderReturnedInTransit;
use App\Listeners\Order\Concerns\IncrementsDailyStats;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Increments the pre-computed daily_stats_reasons counter for a
 * cancellation or return reason code (PRD section 5: dashboards never
 * aggregate the live orders table). Queued, same as
 * UpdateDailyStatsOnStatusChanged — only the dashboard reads this.
 *
 * Subscribes to the dedicated OrderCancelled/OrderReturnedInTransit events
 * rather than the generic ConfirmationStatusChanged/DeliveryStatusChanged
 * ones, since only these two carry the structured reason code directly.
 */
class RecordDailyStatsReason implements ShouldQueue
{
    use IncrementsDailyStats;

    public function handleOrderCancelled(OrderCancelled $event): void
    {
        $this->incrementDailyStatReason($event->order, 'cancel', $event->reasonCode->value);
    }

    public function handleOrderReturnedInTransit(OrderReturnedInTransit $event): void
    {
        $this->incrementDailyStatReason($event->order, 'return', $event->reasonCode->value);
    }
}
