<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fifth dimension on the daily stats: where the order came from
 * (its source_platform — Shopify, YouCan…, or whatsapp, instagram… for
 * manual orders). Rows carry it alone and combined with the store, and
 * with a product or courier on top, so the dashboard's Source filter can
 * narrow every number the same way the Store filter does. Business-wide
 * rows keep it null; readers filter on it like the other dimensions.
 *
 * The scope-unique index grows a NULL-safe key for it, like the others.
 * Run stats:rebuild afterwards so past days get their per-source rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_stats_summary', function (Blueprint $table) {
            $table->string('source_platform', 60)->nullable()->after('delivery_account_id');
            $table->index(['business_id', 'source_platform', 'stat_date'], 'daily_stats_summary_bus_source_date_idx');
        });

        Schema::table('daily_stats_summary', function (Blueprint $table) {
            // SQLite (tests) can only add VIRTUAL generated columns.
            $expression = "COALESCE(source_platform, '')";

            if (DB::getDriverName() === 'sqlite') {
                $table->string('source_key', 60)->virtualAs($expression);
            } else {
                $table->string('source_key', 60)->storedAs($expression);
            }
        });

        Schema::table('daily_stats_summary', function (Blueprint $table) {
            $table->dropUnique('daily_stats_summary_scope_unique');
            $table->unique(
                ['business_id', 'stat_date', 'store_key', 'product_key', 'agent_key', 'courier_key', 'source_key'],
                'daily_stats_summary_scope_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('daily_stats_summary', function (Blueprint $table) {
            $table->dropUnique('daily_stats_summary_scope_unique');
            $table->unique(
                ['business_id', 'stat_date', 'store_key', 'product_key', 'agent_key', 'courier_key'],
                'daily_stats_summary_scope_unique',
            );
            $table->dropIndex('daily_stats_summary_bus_source_date_idx');
            $table->dropColumn(['source_key', 'source_platform']);
        });
    }
};
