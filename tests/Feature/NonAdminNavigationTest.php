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
 * A fulfilment agent works the scan workspace in a browser, so they are no
 * longer signed out of the web app on sight. The admin screens are still
 * closed to them — now by the ordinary route gates, like any other role.
 */
test('a fulfilment agent is forbidden the admin web pages', function (string $route) {
    $agent = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);

    $this->actingAs($agent)->get(route($route))->assertForbidden();

    $this->assertAuthenticated();
})->with(['users.index', 'stores.index', 'delivery-couriers.index', 'orders.index']);

test('a fulfilment agent may reach their scan workspace and their own commissions', function (string $route) {
    $agent = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);

    $this->actingAs($agent)->get(route($route))->assertOk();
})->with(['fulfillment.index', 'commission-entries.index']);

/**
 * The dashboard is the app's default landing spot, so a stale bookmark or
 * a fallback redirect lands there — a fulfilment agent is forwarded to
 * their workspace rather than shown a 403, which would read as a broken
 * account.
 */
test('a fulfilment agent hitting the dashboard is forwarded to their workspace', function () {
    $agent = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertRedirect(route('fulfillment.index'));
});

test('a super admin hitting the dashboard is forwarded to the platform panel', function () {
    $this->actingAs(superAdmin())
        ->get(route('dashboard'))
        ->assertRedirect(route('super-admin.home'));
});

test('every other role still gets the dashboard itself', function (UserRole $role) {
    $user = makeBusinessUser(['role' => $role]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
})->with([UserRole::ADMIN, UserRole::CONFIRMATION_AGENT]);

test('admins can reach stores, delivery couriers, and the team page', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)->get(route('users.index'))->assertOk();
    $this->actingAs($admin)->get(route('stores.index'))->assertOk();
    $this->actingAs($admin)->get(route('delivery-couriers.index'))->assertOk();
});

test('the businesses routes no longer exist', function () {
    $this->assertFalse(Route::has('businesses.index'));
});
