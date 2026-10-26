<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Shopify connection in progress. Each merchant brings their own Shopify
 * app (custom distribution), so its client id and secret must be known
 * before Shopify redirects back to us — and the merchant may finish the
 * install from Shopify's own install link minutes or days later, which is
 * why this lives in a table rather than a short-lived cache entry.
 *
 * One row per (business, shop): re-submitting the form simply replaces
 * the keys. The row is deleted once the store is connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_connection_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('shop', 120);
            $table->string('client_id', 120);
            $table->text('client_secret');
            $table->string('state', 64)->unique();
            $table->timestamps();

            $table->unique(['business_id', 'shop']);
            $table->index('shop');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_connection_requests');
    }
};
