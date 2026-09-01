<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A target can now carry a bonus: "hit 85% confirmation over a month and
 * earn 500 MAD". Nullable, so a target with no bonus behaves exactly as
 * before — the target is still what the agent is measured against, the
 * bonus is only what meeting it pays.
 *
 * Kept on performance_targets rather than a separate bonus_rules table so
 * the threshold an agent is judged on and the threshold they are paid on
 * are the same number, and cannot drift apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_targets', function (Blueprint $table) {
            $table->decimal('bonus_amount', 10, 2)->nullable()->after('target_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('performance_targets', function (Blueprint $table) {
            $table->dropColumn('bonus_amount');
        });
    }
};
