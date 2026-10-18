<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Recounts one business's dashboard stats from its orders. The daily
 * counters only ever add up as orders move, so deleting orders leaves them
 * counted; this rebuild (which skips deleted orders) puts the dashboard
 * back in line. Unique per business, so deleting many orders in a row
 * queues a single recount.
 */
class RebuildBusinessStatsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One pending recount per business for up to 10 minutes. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $businessId) {}

    public function uniqueId(): string
    {
        return (string) $this->businessId;
    }

    public function handle(): void
    {
        Artisan::call('stats:rebuild', ['--business' => $this->businessId]);
    }
}
