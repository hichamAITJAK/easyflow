<?php

namespace App\Events\Performance;

use App\Models\PerformanceTarget;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a confirmation agent's rolling metric (read from the same
 * pre-computed daily_stats_summary used by dashboards — never a live
 * aggregation, per UC-22) drops below their configured PerformanceTarget
 * over its configured period. The seam for notifying the agent (and
 * optionally their manager).
 *
 * Not yet dispatched anywhere: no performance-monitoring job exists yet
 * to evaluate PerformanceTarget rows against rolling stats. This class is
 * the documented seam for that future scheduled job.
 */
class AgentPerformanceWarningTriggered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $agent,
        public readonly PerformanceTarget $target,
        public readonly float $actualValue,
    ) {}
}
