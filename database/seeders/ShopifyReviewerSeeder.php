<?php

namespace Database\Seeders;

use App\Enums\BusinessStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
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

        $subscription = $this->activateSubscription($business);

        $this->command->info('Shopify review accounts ready:');
        $this->command->line("  business_id : {$business->id}");
        $this->command->line("  admin       : {$admin->email}");
        $this->command->line("  agent       : {$agent->email}");
        $this->command->line('  plan        : '.($subscription?->plan->name ?? 'NONE — run PlanSeeder first'));
        $this->command->line('  expires     : '.($subscription?->ends_at?->toDateString() ?? '—'));
        $this->command->warn('Seed sample orders/products for this business before submitting — reviewers reject empty screens.');
    }

    /**
     * Give the reviewer business a long-running active subscription.
     *
     * Without one the account lands on a paywall or account-status screen and
     * the reviewer never reaches the app. The end date is deliberately far out
     * so the account doesn't expire mid-review or before a later re-review.
     *
     * Marked as an internal comp rather than a payment: no money changed
     * hands, and payment_method only offers bank transfer or cash.
     */
    private function activateSubscription(Business $business): ?Subscription
    {
        $plan = Plan::query()->where('is_active', true)->orderBy('id')->first();

        if (! $plan instanceof Plan) {
            $this->command->error('No active plan found. Run: php artisan db:seed --class=PlanSeeder');

            return null;
        }

        $subscription = Subscription::withoutGlobalScopes()
            ->firstOrNew(['business_id' => $business->id, 'plan_id' => $plan->id]);

        $subscription->forceFill([
            'reference_code' => $subscription->reference_code ?? Subscription::nextReferenceCode(),
            'status' => SubscriptionStatus::ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addYears(5),
            'limits' => null,
            'paid_amount' => '0.00',
            'payment_reference' => null,
            'submitted_at' => now(),
            'rejection_reason' => null,
            'notes' => 'Complimentary access for Shopify app review. Not a real payment.',
        ])->save();

        return $subscription->fresh('plan');
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
