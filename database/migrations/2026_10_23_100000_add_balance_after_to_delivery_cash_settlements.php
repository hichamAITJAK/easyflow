<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the delivery guy still owes after a hand-over (positive) or paid
 * ahead (negative). It carries into his next hand-over, so a short one
 * never wipes his balance to zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_cash_settlements', function (Blueprint $table) {
            $table->decimal('balance_after', 12, 2)->default(0)->after('difference_amount');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_cash_settlements', function (Blueprint $table) {
            $table->dropColumn('balance_after');
        });
    }
};
