<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each step of a parcel's journey exactly as the courier reports it
 * (status label, the courier's own time, payment state), saved by the
 * hourly status sync. One row per step: the unique key lets every sync
 * re-send the full history without duplicating what is already stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('status'); // the courier's own label, e.g. "Mise en distribution"
            $table->string('delivery_status')->nullable(); // what it maps to on our side, if anything
            $table->string('payment_state')->nullable(); // e.g. Coliix "Payé" / "Non Payé"
            $table->timestamp('happened_at')->nullable(); // the courier's time for this step
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_id', 'status', 'happened_at'], 'courier_tracking_events_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_tracking_events');
    }
};
