<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock movements can now record a product without variants: product_id
 * names the product every movement belongs to, and product_variant_id is
 * only set when the stock that moved is a variant's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('business_id')
                ->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id')->nullable()->change();

            $table->index(['business_id', 'product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'product_id', 'created_at']);
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
