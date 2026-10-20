<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash a delivery guy collected on delivered parcels and handed to the
 * business. One row per hand-over; the parcels it covers point at it, so
 * "cash owed" is simply his delivered parcels with no settlement yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_cash_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('parcels_count');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'user_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_cash_settlement_id')->nullable()->after('delivery_guy_id')
                ->constrained('delivery_cash_settlements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_cash_settlement_id');
        });

        Schema::dropIfExists('delivery_cash_settlements');
    }
};
