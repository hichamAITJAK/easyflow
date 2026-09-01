<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->text('name');
            $table->text('phone');
            $table->string('phone_hash');
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('delivered_orders_count')->default(0);
            $table->unsignedInteger('returned_orders_count')->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->boolean('is_best_customer')->default(false);
            $table->boolean('is_blacklisted')->default(false);
            $table->timestamps();

            $table->unique(['business_id', 'phone_hash']);
            $table->index(['business_id', 'is_best_customer']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
