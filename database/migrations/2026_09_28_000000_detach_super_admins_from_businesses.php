<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Detach every super admin from a business. The seeder used to hand the
 * platform owner a throwaway business via the user factory default, and
 * users cascade-delete with their business — so removing that business
 * deleted the owner's account. The User model now nulls business_id for
 * the role on every save; this repairs rows written before that guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'super_admin')
            ->whereNotNull('business_id')
            ->update(['business_id' => null]);
    }

    public function down(): void
    {
        // Irreversible by design: the business a super admin was wrongly
        // attached to is not knowable, and re-attaching one would be wrong.
    }
};
