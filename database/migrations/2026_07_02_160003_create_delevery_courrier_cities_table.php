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
        Schema::create('delevery_courrier_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('delivery_courriers')->onDelete('cascade');
            $table->string('external_courrier_id')->nullable();
            $table->string('name')->index();
            $table->string('arabic_name')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delevery_courrier_cities');
    }
};
