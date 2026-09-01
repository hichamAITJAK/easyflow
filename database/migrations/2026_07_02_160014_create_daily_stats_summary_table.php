<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-computed dashboard stats (PRD section 5: dashboards never aggregate
 * the live orders table). One row per business × store × product × agent ×
 * courier (delivery account) × day; the NULL slots of a row mark which
 * dimensions it rolls up.
 *
 * Money and durations ride alongside the counts:
 * - revenue_confirmed: order value at confirmation (pipeline reporting).
 * - revenue_delivered: collected money from delivered orders — the
 *   dashboard's "Total earned" and per-store/product/courier "Earned".
 * - confirm_seconds_total / delivery_seconds_total: duration sums;
 *   averages derive as sum ÷ confirmed_count / delivered_count.
 *
 * The writer is a queued listener doing find-or-create, so the dimension
 * tuple carries a unique index — without it two concurrent jobs create
 * twin rows and split counts. MySQL treats NULLs as distinct inside
 * unique indexes, so the index goes over stored COALESCE(x, 0) key
 * columns (0 = "not scoped to this dimension") instead of the nullable
 * columns themselves.
 *
 * The dimension columns are deliberately NOT foreign keys: MySQL/MariaDB
 * refuse a cascading FK on a column that feeds a stored generated column
 * (errno 1215), and this is a derived cache — rebuildable at any time via
 * stats:rebuild, which also clears rows whose store/product/agent/courier
 * has since been deleted. Only business_id (not part of any generated
 * key) keeps a real FK.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_stats_summary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('delivery_account_id')->nullable();
            $table->date('stat_date');
            $table->integer('orders_count')->default(0);
            $table->integer('assigned_count')->default(0);
            $table->integer('submitted_to_courier_count')->default(0);
            $table->integer('refused_count')->default(0);
            $table->integer('confirmed_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('returned_count')->default(0);
            $table->integer('cancelled_count')->default(0);
            $table->decimal('confirmation_rate', 5, 2)->nullable();
            $table->decimal('delivery_success_rate', 5, 2)->nullable();
            $table->decimal('revenue_confirmed', 12, 2)->nullable();
            $table->decimal('revenue_delivered', 12, 2)->nullable();
            $table->decimal('commission_total', 12, 2)->nullable();
            $table->unsignedBigInteger('confirm_seconds_total')->nullable();
            $table->unsignedBigInteger('delivery_seconds_total')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // NULL-safe uniqueness keys, see class docblock.
            $table->unsignedBigInteger('store_key')->storedAs('COALESCE(store_id, 0)');
            $table->unsignedBigInteger('product_key')->storedAs('COALESCE(product_id, 0)');
            $table->unsignedBigInteger('agent_key')->storedAs('COALESCE(agent_id, 0)');
            $table->unsignedBigInteger('courier_key')->storedAs('COALESCE(delivery_account_id, 0)');

            $table->unique(
                ['business_id', 'stat_date', 'store_key', 'product_key', 'agent_key', 'courier_key'],
                'daily_stats_summary_scope_unique',
            );

            $table->index(['business_id', 'stat_date']);
            $table->index(['business_id', 'store_id', 'stat_date']);
            $table->index(['business_id', 'agent_id', 'stat_date']);
            $table->index(
                ['business_id', 'delivery_account_id', 'stat_date'],
                'daily_stats_summary_bus_courier_date_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_stats_summary');
    }
};
