<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the platform owner.
 *
 * Written without the user factory on purpose: factories call fake(), and
 * Faker is a dev dependency that a production `composer install --no-dev`
 * does not ship — so a factory here makes `db:seed` crash on the one
 * server where seeding the reference data actually matters.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('platform.super_admin.email');
        $password = config('platform.super_admin.password');

        if (blank($email) || blank($password)) {
            if (! app()->environment('local', 'testing')) {
                $this->command?->warn('Super admin skipped: set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD in .env, then re-run.');

                return;
            }

            // Convenience for a developer machine only.
            $email = 'email@example.com';
            $password = 'password';
        }

        $user = User::query()
            ->where('email', $email)
            ->where('role', UserRole::SUPER_ADMIN)
            ->first();

        // Re-running the seeder must never reset a live account's password.
        if ($user !== null) {
            $this->command?->line("Super admin {$email} already exists — left untouched.");

            return;
        }

        (new User)->forceFill([
            // Explicit, not left to the User model's saving hook: seeders
            // commonly run with model events disabled. A super admin owns
            // every business and belongs to none.
            'business_id' => null,
            'name' => config('platform.super_admin.name', 'Super Admin'),
            'email' => $email,
            'password' => $password,
            'role' => UserRole::SUPER_ADMIN,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ])->save();

        $this->command?->info("Super admin {$email} created.");
    }
}
