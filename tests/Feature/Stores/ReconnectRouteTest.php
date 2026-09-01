<?php

use App\Enums\StoreConnectionStatus;
use App\Enums\UserRole;
use App\Models\EcommercePlatform;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeStoreOnPlatform(string $slug, array $overrides = []): array
{
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $platform = EcommercePlatform::create(['name' => $slug, 'slug' => $slug]);

    $store = Store::create([
        'business_id' => $admin->business_id,
        'platform_id' => $platform->id,
        'name' => 'Acme Shop',
        'external_store_id' => 'acme.myshopify.com',
        'api_credentials' => 'token',
        'connection_status' => StoreConnectionStatus::FAILED,
        ...$overrides,
    ]);

    return [$store, $admin];
}

test('reconnecting an oauth store redirects out to the platform', function () {
    [$store, $admin] = makeStoreOnPlatform('YouCan');

    $this->actingAs($admin)
        ->get(route('stores.reconnect', $store))
        ->assertRedirect();
});

test('reconnecting a pasted-credential store is not a redirect out', function () {
    [$store, $admin] = makeStoreOnPlatform('WooCommerce');

    // WooCommerce has no OAuth flow, so this endpoint has nowhere to send
    // the merchant — the stores page opens its dialog instead.
    $this->actingAs($admin)
        ->get(route('stores.reconnect', $store))
        ->assertRedirect(route('stores.index'));
});

test('another business cannot reconnect a store', function () {
    [$store] = makeStoreOnPlatform('YouCan');
    $intruder = makeBusinessUser(['role' => UserRole::ADMIN]);

    // 404, not 403: Store is scoped, so binding never resolves it.
    $this->actingAs($intruder)
        ->get(route('stores.reconnect', $store))
        ->assertNotFound();
});

test('the create page opens on the platform of the store being reconnected', function () {
    [$store, $admin] = makeStoreOnPlatform('WooCommerce');

    $this->actingAs($admin)
        ->get(route('stores.create', ['reconnect' => $store->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('reconnecting.id', $store->id)
            ->where('reconnecting.platform_slug', 'WooCommerce')
        );
});

test('the reconnect parameter cannot surface another business store', function () {
    [$store] = makeStoreOnPlatform('WooCommerce');
    $intruder = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($intruder)
        ->get(route('stores.create', ['reconnect' => $store->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('reconnecting', null));
});
