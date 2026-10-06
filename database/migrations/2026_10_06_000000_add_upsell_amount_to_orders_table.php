<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Signed: how much a confirmation agent's edits moved the items
            // total from what the order came in with. Positive is an upsell,
            // negative a down-sell, null when an agent never changed it.
            $table->decimal('upsell_amount', 10, 2)->nullable()->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('upsell_amount');
        });
    }
};
