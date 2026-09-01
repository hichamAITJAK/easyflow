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
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('platform_id')->constrained('ecommerce_platforms')->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('domain')->nullable();
            $table->text('logo_url')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->string('external_store_id')->nullable();
            $table->text('api_credentials');
            $table->text('webhook_secret')->nullable();
            $table->string('connection_status', 30)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index(['business_id', 'platform_id']);
            $table->unique(['platform_id', 'external_store_id']);
            $table->unique(['business_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
