<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions were removed from the product: no trial, no plans, no
 * payment claims, no access gating. The tenant app is open to every
 * active business. Drops both tables; subscriptions first because it
 * holds the foreign key onto plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }

    public function down(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price', 10, 2);
            $table->string('currency', 3)->default('MAD');
            $table->unsignedInteger('duration_days');
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->onDelete('restrict');
            $table->string('status', 20);
            $table->string('reference_code')->unique();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('limits')->nullable();
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['status', 'ends_at']);
        });
    }
};
