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
        Schema::create('delivery_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('courier_id')->constrained('delivery_courriers')->onDelete('cascade');
            $table->foreignId('collect_city_id')->nullable()->constrained('delevery_courrier_cities')->nullOnDelete();
            // A courier's API gives us nothing stable to identify "the same
            // account" with (Sendit's key pair and OzonExpress's ozon_id can
            // both be regenerated for one real account), so a business can
            // connect multiple accounts per courier and this user-entered
            // label is what tells them apart — both for duplicate
            // prevention (unique per business+courier below) and for
            // display everywhere an account is picked (shipment creation,
            // courier cards).
            $table->string('label');
            $table->text('api_credentials');
            $table->string('webhook_secret')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status');
            $table->timestamps();

            $table->index('business_id');
            $table->index(['business_id', 'courier_id']);
            $table->unique(['business_id', 'courier_id', 'label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_accounts');
    }
};
