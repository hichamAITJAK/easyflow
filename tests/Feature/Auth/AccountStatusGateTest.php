<?php

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('disabled users are blocked at login and redirected to the account status page', function () {
    $user = User::factory()->create(['status' => UserStatus::DISABLED]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('account-status', ['reason' => 'user-disabled']));
});

test('invited users are blocked at login and redirected to the account status page', function () {
    $user = User::factory()->create(['status' => UserStatus::INVITED]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('account-status', ['reason' => 'user-invited']));
});

test('users of a suspended business are blocked at login', function () {
    $business = Business::create([
        'name' => 'Test Business',
        'slug' => 'test-business',
        'status' => BusinessStatus::SUSPENDED,
    ]);

    $user = User::factory()->create([
        'status' => UserStatus::ACTIVE,
        'business_id' => $business->id,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('account-status', ['reason' => 'business-suspended']));
});

test('users of a cancelled business are blocked at login', function () {
    $business = Business::create([
        'name' => 'Test Business',
        'slug' => 'test-business-2',
        'status' => BusinessStatus::CANCELLED,
    ]);

    $user = User::factory()->create([
        'status' => UserStatus::ACTIVE,
        'business_id' => $business->id,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('account-status', ['reason' => 'business-cancelled']));
});

test('active users of an active business can log in normally', function () {
    $business = Business::create([
        'name' => 'Test Business',
        'slug' => 'test-business-3',
        'status' => BusinessStatus::ACTIVE,
    ]);

    $user = User::factory()->create([
        'status' => UserStatus::ACTIVE,
        'business_id' => $business->id,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('fulfilment agents log in to the scan workspace rather than the web app', function () {
    $business = Business::create([
        'name' => 'Test Business',
        'slug' => 'test-business-4',
        'status' => BusinessStatus::ACTIVE,
    ]);

    $user = User::factory()->create([
        'status' => UserStatus::ACTIVE,
        'role' => UserRole::FULFILMENT_AGENT,
        'business_id' => $business->id,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('fulfillment.index'));
});

/**
 * Logging in must not hand the role the rest of the web app. The agent
 * stays signed in — the admin screens are closed by the route gates rather
 * than by signing them out, which is what changed when the mobile-only
 * rule was removed.
 */
test('a logged-in fulfilment agent is still refused the admin web pages', function () {
    $user = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();

    $this->get(route('orders.index'))->assertForbidden();

    $this->assertAuthenticated();
});

test('fulfilment agents can still log in on the mobile api', function () {
    $business = Business::create([
        'name' => 'Test Business',
        'slug' => 'test-business-5',
        'status' => BusinessStatus::ACTIVE,
    ]);

    $user = User::factory()->create([
        'status' => UserStatus::ACTIVE,
        'role' => UserRole::FULFILMENT_AGENT,
        'business_id' => $business->id,
    ]);

    $this->postJson(route('api.mobile.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'test-device',
    ])->assertOk();
});

test('account status page renders known reasons and 404s on unknown ones', function () {
    $this->get(route('account-status', ['reason' => 'user-disabled']))->assertOk();
    $this->get(route('account-status', ['reason' => 'not-a-real-reason']))->assertNotFound();
});
