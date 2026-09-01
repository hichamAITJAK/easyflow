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
        Schema::create('courier_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('delivery_account_id')->constrained('delivery_accounts')->onDelete('cascade');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('expected_amount', 10, 2)->default(0);
            $table->decimal('actual_amount', 10, 2)->nullable();
            $table->decimal('difference_amount', 10, 2)->nullable();
            $table->string('status')->default('pending'); // pending, reconciled, disputed
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'delivery_account_id', 'period_end'], 'courier_settlements_bus_acc_period_idx');
            $table->index(['business_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courier_settlements');
    }
};
