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
        Schema::create('performance_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('metric'); // confirmation_rate, delivery_success_rate
            $table->decimal('target_percentage', 5, 2);
            $table->string('period'); // daily, weekly, monthly
            $table->unsignedInteger('min_orders_for_evaluation')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'user_id', 'metric']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_targets');
    }
};
