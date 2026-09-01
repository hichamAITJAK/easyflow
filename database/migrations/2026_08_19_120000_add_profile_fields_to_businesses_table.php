<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business identity fields for invoicing. All nullable: existing businesses
 * predate this and the app must keep working before an admin fills them in
 * — the invoice template falls back to the trading name alone, exactly as
 * it did before.
 *
 * ice/rc/if_number are the Moroccan company identifiers a compliant
 * invoice carries (Identifiant Commun de l'Entreprise, Registre de
 * Commerce, Identifiant Fiscal). `if` is a PHP reserved word, hence
 * if_number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('logo')->nullable()->after('legal_name');
            $table->string('ice', 50)->nullable()->after('logo');
            $table->string('rc', 50)->nullable()->after('ice');
            $table->string('if_number', 50)->nullable()->after('rc');
            $table->string('phone', 30)->nullable()->after('if_number');
            $table->string('email')->nullable()->after('phone');
            $table->string('address')->nullable()->after('email');
            $table->string('city', 100)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name', 'logo', 'ice', 'rc', 'if_number',
                'phone', 'email', 'address', 'city',
            ]);
        });
    }
};
