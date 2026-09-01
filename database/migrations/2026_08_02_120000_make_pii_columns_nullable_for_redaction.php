<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make the personal-data columns nullable so they can be erased in place.
 *
 * Shopify's mandatory `customers/redact` and `shop/redact` webhooks require
 * the app to erase a customer's personal data on request. The order rows
 * themselves have to stay: the commission ledger is append-only and
 * daily_stats_summary references these orders, so deleting them would corrupt
 * the tenant's financial history. Nulling the PII columns is what erasure
 * means here — but they were created NOT NULL, which made that impossible.
 *
 * `stores.api_credentials` is included for the same reason: shop/redact must
 * be able to drop a shop's access token, and app/uninstalled clears the token
 * Shopify has already revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->text('customer_name')->nullable()->change();
            $table->text('customer_phone')->nullable()->change();
            $table->text('customer_address')->nullable()->change();
            $table->string('customer_phone_hash')->nullable()->change();
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->text('name')->nullable()->change();
            $table->text('phone')->nullable()->change();
            $table->string('phone_hash')->nullable()->change();
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->text('api_credentials')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible in a meaningful sense: rows redacted while this
        // migration was applied hold NULLs that cannot be restored, so
        // re-adding NOT NULL would fail on exactly the data this exists to
        // erase. Reverting the schema is left as a deliberate manual step.
    }
};
