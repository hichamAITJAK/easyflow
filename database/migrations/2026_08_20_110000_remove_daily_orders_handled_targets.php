<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops any daily_orders_handled target rows.
 *
 * The metric was removed from PerformanceMetric: it was a count against a
 * quota rather than a percentage rate, so the evaluator never judged it,
 * no warning ever fired for it, and a bonus attached to it could never be
 * earned. Leaving rows behind would mean a stored value the enum can no
 * longer cast — every read of that row would throw.
 *
 * Irreversible by design: down() can't recreate targets whose metric no
 * longer exists in the enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('performance_targets')
            ->where('metric', 'daily_orders_handled')
            ->delete();
    }

    public function down(): void
    {
        // No-op: the metric no longer exists, so these rows cannot be
        // restored in a form the application could read.
    }
};
