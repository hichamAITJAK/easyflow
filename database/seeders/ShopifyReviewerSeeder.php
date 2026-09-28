<?php

namespace Database\Seeders;

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the accounts Shopify's app reviewers log in with.
 *
 * Shopify review requires working credentials for a non-embedded app —
 * reviewers can't evaluate EasyFlow from the Shopify admin, since everything
 * happens on our own domain. Two accounts are seeded so they can see both
 * sides of the product: the admin view and a confirmation agent's scoped
 * queue.
 *
 * Both are created email-verified and without two-factor, because Shopify
 * explicitly asks that review accounts not sit behind a verification link or
 * a 2FA challenge.
 *
 * Passwords come from the environment so no credential is committed:
 *
 *   SHOPIFY_REVIEW_PASSWORD=... php artisan db:seed --class=ShopifyReviewerSeeder
 *
 * Idempotent — safe to re-run to reset the accounts before a re-review.
 */
class ShopifyReviewerSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) config('services.shopify.review_password', '');

        if ($password === '') {
            $this->command->error(
                'SHOPIFY_REVIEW_PASSWORD is not set. Re-run with it, e.g.: '
                .'SHOPIFY_REVIEW_PASSWORD=... php artisan db:seed --class=ShopifyReviewerSeeder'
            );

            return;
        }

        $business = Business::firstOrCreate(
            ['slug' => 'shopify-review'],
            ['name' => 'Shopify Review', 'status' => BusinessStatus::ACTIVE],
        );

        $admin = $this->upsertUser(
            business: $business,
            email: 'shopify-review@easyflow.ma',
            name: 'Shopify Reviewer',
            role: UserRole::ADMIN,
            password: $password,
        );

        $agent = $this->upsertUser(
            business: $business,
            email: 'shopify-review-agent@easyflow.ma',
            name: 'Shopify Reviewer (Agent)',
            role: UserRole::CONFIRMATION_AGENT,
            password: $password,
        );

        $this->command->info('Shopify review accounts ready:');
        $this->command->line("  business_id : {$business->id}");
        $this->command->line("  admin       : {$admin->email}");
        $this->command->line("  agent       : {$agent->email}");
        $this->command->warn('Seed sample orders/products for this business before submitting — reviewers reject empty screens.');
    }

    /**
     * Create or reset one reviewer account.
     *
     * Existing rows are reset rather than skipped so a re-run restores a
     * known-good password and clears any 2FA a previous session enabled.
     */
    private function upsertUser(
        Business $business,
        string $email,
        string $name,
        UserRole $role,
        string $password,
    ): User {
        $user = User::withoutGlobalScopes()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'business_id' => $business->id,
            'name' => $name,
            'role' => $role,
            'status' => UserStatus::ACTIVE,
            'password' => $password,
            'email_verified_at' => now(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $user;
    }
}
