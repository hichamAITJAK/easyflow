<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hour-of-day confirmation stats for the dashboard's "Best hour to
 * confirm" chart (UC-23). Deliberately a separate table from
 * daily_stats_summary: that table's grain is one row per day per scope,
 * and folding 24 hour buckets into it would either add 24 columns or
 * corrupt its grain. attempts_count counts agent contact outcomes
 * (no_answer, busy, confirmed, ...); confirmed_count the successes —
 * rate = confirmed ÷ attempts per hour.
 *
 * agent_id NULL = the business-wide bucket. Same COALESCE-key trick as
 * daily_stats_summary for NULL-safe uniqueness under the queued writer —
 * and for the same reason, agent_id carries no FK: MySQL/MariaDB refuse a
 * cascading FK on a column that feeds a stored generated column (errno
 * 1215), and this derived cache is rebuildable via stats:rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hourly_confirmation_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->date('stat_date');
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('attempts_count')->default(0);
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unsignedBigInteger('agent_key')->storedAs('COALESCE(`agent_id`, 0)');

            $table->unique(
                ['business_id', 'stat_date', 'hour', 'agent_key'],
                'hourly_confirmation_stats_scope_unique',
            );
            $table->index(['business_id', 'stat_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hourly_confirmation_stats');
    }
};
