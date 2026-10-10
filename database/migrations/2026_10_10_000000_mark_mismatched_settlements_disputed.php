<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settlements used to be saved as "reconciled" whatever the difference.
 * Any reconciled row whose actual amount is at least 1 MAD away from the
 * expected amount is really an open dispute; this marks them so.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('courier_settlements')
            ->where('status', 'reconciled')
            ->where(fn ($query) => $query
                ->where('difference_amount', '<=', -1)
                ->orWhere('difference_amount', '>=', 1))
            ->update(['status' => 'disputed']);
    }

    /**
     * Not reversible: once flipped, a disputed row can't be told apart
     * from one an admin disputed by hand.
     */
    public function down(): void {}
};
