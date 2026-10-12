<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A confirmation agent's upsell commission, on their default rule: a fixed
 * amount per upsold order, or a percentage of the upsell. Null when the
 * agent earns nothing extra for upsells.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->string('upsell_amount_type')->nullable()->after('amount'); // fixed, percentage
            $table->decimal('upsell_amount', 10, 2)->nullable()->after('upsell_amount_type');
        });
    }

    public function down(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropColumn(['upsell_amount_type', 'upsell_amount']);
        });
    }
};
