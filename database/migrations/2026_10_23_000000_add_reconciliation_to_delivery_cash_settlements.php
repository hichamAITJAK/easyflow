<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery guy's hand-over now records what was actually received next
 * to what his parcels add up to, so it reconciles like a courier period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_cash_settlements', function (Blueprint $table) {
            $table->decimal('received_amount', 12, 2)->nullable()->after('amount');
            $table->decimal('difference_amount', 12, 2)->default(0)->after('received_amount');
            $table->string('status')->default('reconciled')->after('parcels_count');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_cash_settlements', function (Blueprint $table) {
            $table->dropColumn(['received_amount', 'difference_amount', 'status']);
        });
    }
};
