<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('registering creates a business admin and starts the free trial', function () {
    $response = $this->post(route('register.store'), [
        'business_name' => 'Atlas Store',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::ADMIN)
        ->and($user->status)->toBe(UserStatus::ACTIVE)
        ->and($user->business->name)->toBe('Atlas Store')
        ->and($user->business->slug)->toBe('atlas-store');

    $trial = $user->business->subscriptions()->sole();

    expect($trial->status)->toBe(SubscriptionStatus::TRIALING)
        ->and($trial->plan_id)->toBeNull()
        ->and($trial->ends_at->isFuture())->toBeTrue()
        ->and($trial->limits)->toBe(config('subscription.trial_limits'));

    $this->assertAuthenticatedAs($user);

    // A fresh trial passes the subscription gate straight away.
    $this->get(route('dashboard'))->assertOk();
});

test('a colliding business name gets a disambiguated slug', function () {
    $this->post(route('register.store'), [
        'business_name' => 'Atlas Store',
        'name' => 'First',
        'email' => 'first@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    auth()->logout();

    $this->post(route('register.store'), [
        'business_name' => 'Atlas Store',
        'name' => 'Second',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::where('email', 'second@example.com')->firstOrFail()->business->slug)
        ->toBe('atlas-store-2');
});

test('registration requires a business name', function () {
    $this->post(route('register.store'), [
        'business_name' => '',
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('business_name');

    $this->assertGuest();
});

test('registration rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register.store'), [
        'business_name' => 'Atlas Store',
        'name' => 'Someone',
        'email' => 'taken@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});
