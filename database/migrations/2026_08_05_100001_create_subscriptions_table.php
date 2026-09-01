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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->onDelete('restrict');
            $table->string('status', 20); // trialing, pending, active, rejected, expired, cancelled
            // Shown to the user as the transfer reference so bank statement
            // lines can be matched to a request, e.g. "SUB-2031".
            $table->string('reference_code')->unique();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // Snapshot of plan limits at activation — later plan edits must
            // not change what an already-paying business bought.
            $table->json('limits')->nullable();
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->string('payment_method', 20)->nullable(); // bank_transfer, cash
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
