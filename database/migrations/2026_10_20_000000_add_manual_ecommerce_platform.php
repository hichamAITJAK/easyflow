<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "Manual" platform backs stores with no online shop: a brand that
 * only sells through WhatsApp, Instagram, phone calls… still needs a store
 * so its manual orders can be told apart from another brand's. Stores on
 * it carry connection_status = manual, which keeps them out of every
 * platform sync (those only touch connected stores).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('ecommerce_platforms')->where('slug', 'manual')->exists();

        if (! $exists) {
            DB::table('ecommerce_platforms')->insert([
                'name' => 'Manual',
                'slug' => 'manual',
                'description' => 'A store with no online shop: orders are keyed in by hand from WhatsApp, Instagram, phone calls and the like.',
                'logo_url' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Left in place: stores may already point at it.
    }
};
