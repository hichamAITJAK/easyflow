<?php

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('non-admin roles cannot reach the team (users) page', function (UserRole $role) {
    $user = makeBusinessUser(['role' => $role]);

    $this->actingAs($user)->get(route('users.index'))->assertForbidden();
})->with([UserRole::CONFIRMATION_AGENT]);

test('non-admin roles cannot reach the stores page', function (UserRole $role) {
    $user = makeBusinessUser(['role' => $role]);

    $this->actingAs($user)->get(route('stores.index'))->assertForbidden();
})->with([UserRole::CONFIRMATION_AGENT]);

test('non-admin roles cannot reach the delivery couriers page', function (UserRole $role) {
    $user = makeBusinessUser(['role' => $role]);

    $this->actingAs($user)->get(route('delivery-couriers.index'))->assertForbidden();
})->with([UserRole::CONFIRMATION_AGENT]);

/**
 * A fulfilment agent is mobile-only, so they never get as far as a
 * permission check on a web page — EnsureAccountStillUsable bounces them
 * off the web app entirely, even mid-session.
 */
test('a fulfilment agent is redirected off every web page, not merely forbidden', function (string $route) {
    $agent = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);

    $this->actingAs($agent)
        ->get(route($route))
        ->assertRedirect(route('account-status', ['reason' => 'mobile-only-role']));

    $this->assertGuest();
})->with(['users.index', 'stores.index', 'delivery-couriers.index', 'orders.index', 'dashboard']);

test('admins can reach stores, delivery couriers, and the team page', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)->get(route('users.index'))->assertOk();
    $this->actingAs($admin)->get(route('stores.index'))->assertOk();
    $this->actingAs($admin)->get(route('delivery-couriers.index'))->assertOk();
});

test('the businesses routes no longer exist', function () {
    $this->assertFalse(Route::has('businesses.index'));
});
