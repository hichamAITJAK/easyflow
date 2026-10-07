<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Set once someone edits stock in EasyFlow. From then on the
            // platform sync leaves inventory_quantity alone (on the product
            // and its variants) so a warehouse count is never clobbered.
            $table->boolean('stock_managed_locally')->default(false)->after('inventory_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_managed_locally');
        });
    }
};
