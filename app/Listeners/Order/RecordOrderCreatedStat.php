<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderCreated;
use App\Listeners\Order\Concerns\IncrementsDailyStats;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Increments the pre-computed daily_stats_summary row(s) for a newly
 * created order (PRD section 5: dashboards never aggregate the live
 * orders table). Queued — the confirmation screen and order lists don't
 * read this listener's output synchronously, only the dashboard does.
 *
 * Excludes is_test orders unconditionally (UC-25): stats must never
 * include a test order under any circumstance.
 */
class RecordOrderCreatedStat implements ShouldQueue
{
    use IncrementsDailyStats;

    public function handle(OrderCreated $event): void
    {
        $this->incrementDailyStat($event->order, 'orders_count');
    }
}
