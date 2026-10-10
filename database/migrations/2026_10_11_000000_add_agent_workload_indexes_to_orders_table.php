<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Covering indexes for the Team page's workload column (and the
 * dashboard's per-agent backlog), so both stay index-only reads however
 * large the orders table grows:
 *
 * - confirmation agents: open orders per agent — the IN on the handful of
 *   open statuses narrows the scan, then it groups by agent;
 * - fulfilment agents: parcels to prepare / returns to receive per store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(
                ['business_id', 'confirmation_status', 'assigned_agent_id', 'is_test'],
                'orders_agent_open_work_index',
            );
            $table->index(
                ['business_id', 'delivery_status', 'store_id'],
                'orders_store_parcel_work_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_agent_open_work_index');
            $table->dropIndex('orders_store_parcel_work_index');
        });
    }
};
