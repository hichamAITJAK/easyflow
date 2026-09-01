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
        Schema::create('commission_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->onDelete('set null');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->decimal('amount', 10, 2);
            $table->string('entry_type')->default('earned'); // earned, reversal
            $table->foreignId('reversed_entry_id')->nullable()->constrained('commission_ledger_entries')->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'user_id', 'created_at']);
            $table->index(['business_id', 'order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_ledger_entries');
    }
};
