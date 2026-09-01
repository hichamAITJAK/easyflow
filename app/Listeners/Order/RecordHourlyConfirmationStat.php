<?php

namespace App\Listeners\Order;

use App\Enums\OrderConfirmationStatus;
use App\Events\Order\ConfirmationStatusChanged;
use App\Models\HourlyConfirmationStat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Buckets agent contact outcomes by hour of day, feeding the dashboard's
 * "Best hour to confirm" chart (UC-23). An "attempt" is any transition an
 * agent produces by contacting (or trying to contact) the client — the
 * statuses below — bucketed by the hour the transition happened, NOT the
 * order's creation hour: the chart answers "when should we call?", so
 * the call's own time is the fact that matters.
 *
 * Writes the business-wide bucket plus the agent's bucket, mirroring the
 * scoping convention of daily_stats_summary. Queued for the same reason
 * that table's writer is: only the dashboard reads it.
 */
class RecordHourlyConfirmationStat implements ShouldQueue
{
    private const ATTEMPT_STATUSES = [
        OrderConfirmationStatus::CONFIRMED,
        OrderConfirmationStatus::CONFIRMED_FOLLOWUP,
        OrderConfirmationStatus::CALLBACK,
        OrderConfirmationStatus::FAKE,
        OrderConfirmationStatus::VOICEMAIL,
        OrderConfirmationStatus::NO_ANSWER,
        OrderConfirmationStatus::BUSY,
        OrderConfirmationStatus::WHATSAPP_SENT,
    ];

    public function handle(ConfirmationStatusChanged $event): void
    {
        $order = $event->order;

        if ($order->is_test || ! in_array($event->toStatus, self::ATTEMPT_STATUSES, true)) {
            return;
        }

        $now = Carbon::now();
        $confirmed = $event->toStatus === OrderConfirmationStatus::CONFIRMED;

        $this->bumpBucket($order->business_id, null, $now, $confirmed);

        if ($order->assigned_agent_id !== null) {
            $this->bumpBucket($order->business_id, $order->assigned_agent_id, $now, $confirmed);
        }
    }

    private function bumpBucket(int $businessId, ?int $agentId, Carbon $at, bool $confirmed): void
    {
        $find = fn () => HourlyConfirmationStat::where('business_id', $businessId)
            ->where('agent_id', $agentId)
            ->whereDate('stat_date', $at->toDateString())
            ->where('hour', $at->hour)
            ->first();

        $bucket = $find();

        if ($bucket === null) {
            try {
                $bucket = HourlyConfirmationStat::create([
                    'business_id' => $businessId,
                    'agent_id' => $agentId,
                    'stat_date' => $at->toDateString(),
                    'hour' => $at->hour,
                ]);
            } catch (QueryException $exception) {
                // The scope-unique index turned a concurrent-writer race
                // into a duplicate-key error; use the other job's row.
                $bucket = $find() ?? throw $exception;
            }
        }

        $bucket->increment('attempts_count');

        if ($confirmed) {
            $bucket->increment('confirmed_count');
        }
    }
}
