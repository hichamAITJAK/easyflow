<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `daily_stats_summary.store_id` has no foreign key on purpose — stats
     * are pre-computed history that must survive a store being disconnected.
     * But the dashboard resolves the store name by joining back to `stores`
     * and drops any row it can't resolve, so a deleted store's history
     * silently disappeared from the per-store breakdown while still counting
     * toward the totals.
     *
     * This snapshots the name at write time so the breakdown stays readable
     * and keeps summing to the total after a store is gone.
     */
    public function up(): void
    {
        Schema::table('daily_stats_summary', function (Blueprint $table) {
            $table->string('store_name')->nullable()->after('store_id');
        });

        // Backfill from the stores that still exist. Rows whose store was
        // already deleted stay null and render as "Deleted store".
        DB::table('daily_stats_summary')
            ->whereNotNull('store_id')
            ->update([
                'store_name' => DB::raw('(select name from stores where stores.id = daily_stats_summary.store_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('daily_stats_summary', function (Blueprint $table) {
            $table->dropColumn('store_name');
        });
    }
};
