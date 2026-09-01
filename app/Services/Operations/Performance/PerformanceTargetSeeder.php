<?php

namespace App\Services\Operations\Performance;

use App\Enums\PerformanceMetric;
use App\Models\Business;
use App\Models\PerformanceTarget;

/**
 * Seeds a new business with its business-wide performance targets
 * (user_id null) from config('performance.defaults').
 *
 * These are the rows an agent is measured against when they have no
 * target of their own — AgentPerformanceEvaluator::targetsFor() prefers an
 * agent-specific row and falls back to the business-wide one. Without them
 * an agent created without explicit targets is never evaluated at all: the
 * evaluator returns an empty target list and the nightly warning command
 * skips them silently.
 *
 * One row set per business rather than a copy per agent, so raising a
 * business's default later moves every non-overridden agent with it.
 */
class PerformanceTargetSeeder
{
    /**
     * Create the default business-wide targets, skipping any metric the
     * business already has a business-wide row for — so this is safe to
     * call on an existing business (the backfill command does exactly
     * that) without duplicating or overwriting a tuned value.
     *
     * @return int Number of rows created.
     */
    public function seed(Business $business): int
    {
        $existing = PerformanceTarget::where('business_id', $business->id)
            ->whereNull('user_id')
            ->pluck('metric')
            ->map(fn (PerformanceMetric $metric) => $metric->value)
            ->all();

        $created = 0;

        foreach (config('performance.defaults') as $metric => $value) {
            if (in_array($metric, $existing, true)) {
                continue;
            }

            PerformanceTarget::create([
                'business_id' => $business->id,
                'user_id' => null,
                'metric' => $metric,
                'target_percentage' => $value,
                // The business's starting window; an admin changes it on
                // Settings → Business, and an agent can carry their own.
                'period' => config('performance.default_period'),
                'min_orders_for_evaluation' => (int) config('performance.min_orders_for_evaluation'),
                'is_active' => true,
            ]);

            $created++;
        }

        return $created;
    }
}
