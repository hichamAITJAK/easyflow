<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

uses(RefreshDatabase::class);

function fakeGoogleUser(string $id = 'google-123', string $email = 'user@example.com', string $name = 'Google User'): void
{
    $googleUser = (new GoogleUser)->setRaw([])->map([
        'id' => $id,
        'email' => $email,
        'name' => $name,
    ]);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

it('logs in an existing user matched by google id', function () {
    $admin = makeBusinessUser();
    $admin->forceFill(['google_id' => 'google-123'])->save();

    fakeGoogleUser(id: 'google-123', email: 'other@example.com');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($admin->fresh());
});

it('links google to an existing account matched by email', function () {
    $admin = makeBusinessUser(['email' => 'admin@example.com']);

    fakeGoogleUser(email: 'admin@example.com');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard'));

    expect($admin->fresh()->google_id)->toBe('google-123');
    $this->assertAuthenticatedAs($admin->fresh());
});

it('sends a super admin to the platform panel after google login', function () {
    $superAdmin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'business_id' => null,
        'email' => 'platform@example.com',
        'google_id' => 'google-123',
    ]);

    fakeGoogleUser(email: 'platform@example.com');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('super-admin.businesses.index'));

    $this->assertAuthenticatedAs($superAdmin->fresh());
});

it('blocks a disabled account from logging in with google', function () {
    $agent = makeBusinessUser([
        'email' => 'disabled@example.com',
        'status' => UserStatus::DISABLED,
    ]);
    $agent->forceFill(['google_id' => 'google-123'])->save();

    fakeGoogleUser(email: 'disabled@example.com');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('account-status', ['reason' => 'user-disabled']));

    $this->assertGuest();
});

it('sends a brand-new google user to the complete-registration step', function () {
    fakeGoogleUser(email: 'new@example.com', name: 'New Merchant');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('auth.google.complete'));

    $this->assertGuest();

    $this->get(route('auth.google.complete'))->assertOk();
});

it('creates the business, admin, and trial when registration is completed', function () {
    fakeGoogleUser(email: 'new@example.com', name: 'New Merchant');

    $this->get(route('auth.google.callback'));

    $this->post(route('auth.google.complete.store'), [
        'business_name' => 'Atlas Store',
    ])->assertRedirect(route('dashboard'));

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect($user->google_id)->toBe('google-123')
        ->and($user->role)->toBe(UserRole::ADMIN)
        ->and($user->status)->toBe(UserStatus::ACTIVE)
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->business->name)->toBe('Atlas Store');

    $trial = $user->business->subscriptions()->sole();

    expect($trial->status)->toBe(SubscriptionStatus::TRIALING)
        ->and($trial->plan_id)->toBeNull();

    $this->assertAuthenticatedAs($user);

    $this->get(route('dashboard'))->assertOk();
});

it('redirects to login when completing without a pending google session', function () {
    $this->get(route('auth.google.complete'))->assertRedirect(route('login'));

    $this->post(route('auth.google.complete.store'), [
        'business_name' => 'Atlas Store',
    ])->assertRedirect(route('login'));
});
