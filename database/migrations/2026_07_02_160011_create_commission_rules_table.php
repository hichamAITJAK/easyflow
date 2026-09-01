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
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('payment_mode'); // salary, commission
            // store_id/product_id scope a commission OVERRIDE to a specific
            // store or product — both null means this is the agent's
            // default rate. This is a pricing override, not a visibility
            // grant: see agent_scopes for what an agent can see/work on.
            $table->foreignId('store_id')->nullable()->constrained('stores')->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained('products')->onDelete('cascade');
            $table->string('trigger_status')->nullable(); // required when payment_mode = commission
            $table->string('amount_type')->nullable(); // fixed, percentage — required when payment_mode = commission
            $table->decimal('amount', 10, 2)->nullable(); // required when payment_mode = commission
            $table->decimal('salary_amount', 10, 2)->nullable(); // required when payment_mode = salary
            $table->string('salary_period')->nullable(); // weekly, monthly — required when payment_mode = salary
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // No DB-level uniqueness on (user_id, store_id, product_id):
            // MySQL treats every NULL as distinct in a unique index, so it
            // wouldn't actually catch two rows for the same agent+store
            // (product_id NULL on both). Uniqueness is instead enforced by
            // SyncsAgentCompensation, which always deletes and rebuilds an
            // agent's full set of override rows on every save.
            $table->index(['business_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
