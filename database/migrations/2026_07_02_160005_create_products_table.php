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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            // Null store_id means a manually-added product, owned directly by
            // the business rather than synced from a connected store.
            $table->foreignId('store_id')->nullable()->constrained('stores')->onDelete('cascade');
            $table->string('external_product_id')->nullable();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->longText('description_html')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->integer('inventory_quantity')->nullable();
            $table->text('public_url')->nullable();
            $table->text('thumbnail')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('status')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_test')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'store_id']);
            $table->unique(['store_id', 'external_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
