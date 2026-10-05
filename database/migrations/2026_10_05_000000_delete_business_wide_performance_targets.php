<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "Performance targets" section of Settings → Business is gone, and
 * with it the business-wide target rows it edited (user_id null).
 *
 * Targets set on an individual agent (user_id set) are untouched: they
 * are edited on the team page and still drive that agent's warnings and
 * bonuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('performance_targets')->whereNull('user_id')->delete();
    }

    /**
     * Not reversible: the deleted values are not kept anywhere.
     */
    public function down(): void
    {
        //
    }
};
