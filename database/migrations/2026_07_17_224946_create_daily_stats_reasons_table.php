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
        Schema::create('daily_stats_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('store_id')->nullable()->constrained('stores')->onDelete('cascade');
            $table->foreignId('agent_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->date('stat_date');
            $table->string('reason_type');
            $table->string('reason_code');
            $table->integer('count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['business_id', 'store_id', 'agent_id', 'stat_date', 'reason_type', 'reason_code'], 'daily_stats_reasons_scope_unique');
            $table->index(['business_id', 'stat_date']);
            $table->index(['business_id', 'agent_id', 'stat_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_stats_reasons');
    }
};
