<?php

use App\Enums\UserRole;
use App\Models\EcommercePlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to login', function () {
    $this->get(route('stores.index'))->assertRedirect(route('login'));
    $this->get(route('stores.create'))->assertRedirect(route('login'));
});

test('a super admin with no business cannot start the wizard', function () {
    $user = User::factory()->create(['business_id' => null, 'role' => UserRole::SUPER_ADMIN]);

    $this->actingAs($user)
        ->get(route('stores.create'))
        ->assertForbidden();
});

test('the platform picker lists connectable and coming-soon platforms', function () {
    $user = makeBusinessUser();
    EcommercePlatform::create(['name' => 'Shopify', 'slug' => 'Shopify']);
    EcommercePlatform::create(['name' => 'WooCommerce', 'slug' => 'WooCommerce']);

    $response = $this->actingAs($user)->get(route('stores.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('stores/create')
        ->has('platforms', 2)
    );
});

test('the stores list ships the fields each card renders', function () {
    $user = makeBusinessUser();
    $store = makeStore($user->business_id, [
        'domain' => 'shop.example.com',
        'last_synced_at' => now()->subHours(3),
    ]);

    $response = $this->actingAs($user)->get(route('stores.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('stores/index')
        ->has('stores', 1)
        ->where('stores.0.id', $store->id)
        ->where('stores.0.domain', 'shop.example.com')
        // The card degrades silently to "Never synced" if this column ever
        // falls out of the controller's select(), so it is asserted here
        // rather than left to be noticed in the UI.
        ->has('stores.0.last_synced_at')
        ->has('stores.0.platform.name')
    );
});

// Store creation and OAuth connect coverage live in
// ShopifyConnectionTest / YouCanConnectionTest — Store rows are only
// ever created inside a platform's OAuth callback, not by a separate
// wizard step.
