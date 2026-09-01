<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Events\Subscription\PaymentRequestSubmitted;
use App\Events\Subscription\SubscriptionApproved;
use App\Events\Subscription\SubscriptionExpiringSoon;
use App\Events\Subscription\SubscriptionRejected;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makePlan(): Plan
{
    return Plan::factory()->create(['slug' => 'standard-yearly']);
}

it('lets a business with a running trial reach the tenant app', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
});

it('blocks the tenant app once the trial is over', function () {
    $admin = makeBusinessUser();
    $admin->business->subscriptions()->update(['ends_at' => now()->subDay()]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('subscription.blocked'));
});

it('keeps a paid subscription usable through the grace window and cuts it after', function () {
    $admin = makeBusinessUser();
    $admin->business->subscriptions()->delete();

    $subscription = Subscription::factory()
        ->active()
        ->for($admin->business)
        ->create(['ends_at' => now()->subDays(2)]);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $subscription->update(['ends_at' => now()->subDays(10)]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('subscription.blocked'));
});

it('lets an admin submit a payment request with a receipt', function () {
    Event::fake([PaymentRequestSubmitted::class]);
    Storage::fake('public');

    $plan = makePlan();
    $admin = makeBusinessUser();
    $admin->business->subscriptions()->update(['ends_at' => now()->subDay()]);

    $this->actingAs($admin)
        ->post(route('subscription.payment-requests.store'), [
            'plan_id' => $plan->id,
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'VIR-123',
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect(route('subscription.blocked'));

    $subscription = $admin->business->subscriptions()
        ->where('status', SubscriptionStatus::PENDING)
        ->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->payment_reference)->toBe('VIR-123')
        ->and($subscription->receipt_path)->not->toBeNull();

    Storage::disk('public')->assertExists($subscription->receipt_path);
    Event::assertDispatched(PaymentRequestSubmitted::class);
});

it('refuses a second payment request while one is pending', function () {
    $plan = makePlan();
    $admin = makeBusinessUser();
    Subscription::factory()->pending()->for($admin->business)->create(['plan_id' => $plan->id]);

    $this->actingAs($admin)
        ->from(route('subscription.blocked'))
        ->post(route('subscription.payment-requests.store'), [
            'plan_id' => $plan->id,
            'payment_method' => 'cash',
        ])
        ->assertSessionHasErrors('plan_id');
});

it('forbids agents from submitting a payment request', function () {
    $plan = makePlan();
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    $this->actingAs($agent)
        ->post(route('subscription.payment-requests.store'), [
            'plan_id' => $plan->id,
            'payment_method' => 'cash',
        ])
        ->assertForbidden();
});

it('activates a pending subscription when the super admin approves it', function () {
    Event::fake([SubscriptionApproved::class]);

    $plan = makePlan();
    $admin = makeBusinessUser();
    $pending = Subscription::factory()->pending()->for($admin->business)->create(['plan_id' => $plan->id]);
    $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'business_id' => null]);

    $this->actingAs($superAdmin)
        ->patch(route('super-admin.subscriptions.approve', $pending))
        ->assertRedirect();

    $pending->refresh();

    expect($pending->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and($pending->activated_by)->toBe($superAdmin->id)
        ->and($pending->ends_at->isFuture())->toBeTrue();

    // The old trial is closed the moment a paid cycle starts.
    expect(
        $admin->business->subscriptions()
            ->where('status', SubscriptionStatus::TRIALING)
            ->exists(),
    )->toBeFalse();

    Event::assertDispatched(SubscriptionApproved::class);
});

it('starts an early renewal at the end of the running paid cycle', function () {
    $plan = makePlan();
    $admin = makeBusinessUser();
    $admin->business->subscriptions()->delete();

    $currentEnd = now()->addDays(10);
    Subscription::factory()->active()->for($admin->business)->create(['ends_at' => $currentEnd]);
    $renewal = Subscription::factory()->pending()->for($admin->business)->create(['plan_id' => $plan->id]);
    $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'business_id' => null]);

    app(SubscriptionService::class)->approve($renewal, $superAdmin);

    expect($renewal->refresh()->starts_at->toDateString())->toBe($currentEnd->toDateString());
});

it('rejects a pending subscription with a reason the business can see', function () {
    Event::fake([SubscriptionRejected::class]);

    $plan = makePlan();
    $admin = makeBusinessUser();
    $pending = Subscription::factory()->pending()->for($admin->business)->create(['plan_id' => $plan->id]);
    $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'business_id' => null]);

    $this->actingAs($superAdmin)
        ->patch(route('super-admin.subscriptions.reject', $pending), [
            'reason' => 'No matching transfer found.',
        ])
        ->assertRedirect();

    $pending->refresh();

    expect($pending->status)->toBe(SubscriptionStatus::REJECTED)
        ->and($pending->rejection_reason)->toBe('No matching transfer found.');

    Event::assertDispatched(SubscriptionRejected::class);
});

it('forbids tenant admins from the super admin review queue', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)
        ->get(route('super-admin.subscriptions.index'))
        ->assertForbidden();
});

it('expires past-due subscriptions and fires reminders in the daily check', function () {
    Event::fake([SubscriptionExpiringSoon::class]);

    $endingSoon = makeBusinessUser();
    $endingSoon->business->subscriptions()->update([
        'ends_at' => now()->addDays(3)->addHour(),
    ]);

    $pastDue = makeBusinessUser();
    $pastDue->business->subscriptions()->update(['ends_at' => now()->subHour()]);

    $this->artisan('subscriptions:check')->assertSuccessful();

    expect(
        $pastDue->business->subscriptions()->first()->status,
    )->toBe(SubscriptionStatus::EXPIRED);

    Event::assertDispatched(
        SubscriptionExpiringSoon::class,
        fn (SubscriptionExpiringSoon $event) => $event->daysRemaining === 3
            && $event->subscription->business_id === $endingSoon->business_id,
    );
});
