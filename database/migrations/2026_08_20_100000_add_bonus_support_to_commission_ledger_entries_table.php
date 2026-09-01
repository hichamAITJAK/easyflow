<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the ledger carry a performance bonus alongside per-order commission.
 *
 * A bonus is earned over a period rather than for one order, so order_id
 * becomes nullable and the row records which window it covers. Keeping it
 * in the same ledger means invoice totals, the paid/earned figures and the
 * invoice PDF pick bonuses up with no changes — they all sum `amount` over
 * this table.
 *
 * The unique index is the dedupe guard: one bonus per agent, per metric,
 * per window. Without it a re-run of the awarding command would pay twice.
 * MySQL treats NULLs as distinct in a unique index, so this only constrains
 * rows that actually carry a period — ordinary per-order rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();

            $table->foreignId('performance_target_id')->nullable()->after('commission_rule_id')
                ->constrained('performance_targets')->nullOnDelete();
            $table->string('description')->nullable()->after('amount');
            $table->date('period_start')->nullable()->after('description');
            $table->date('period_end')->nullable()->after('period_start');

            $table->unique(
                ['business_id', 'user_id', 'performance_target_id', 'period_start'],
                'ledger_bonus_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            $table->dropUnique('ledger_bonus_period_unique');
            $table->dropConstrainedForeignId('performance_target_id');
            $table->dropColumn(['description', 'period_start', 'period_end']);
            $table->foreignId('order_id')->nullable(false)->change();
        });
    }
};
