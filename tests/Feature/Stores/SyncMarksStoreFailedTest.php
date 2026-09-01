<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Operations\Products\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeYouCanStoreForSync(): Store
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'YouCan', 'slug' => 'YouCan']);

    return Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'my-shop',
        'external_store_id' => 'my-shop',
        'api_credentials' => json_encode(['access_token' => 'REVOKED']),
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);
}

test('a revoked token during a product sync marks the store failed', function () {
    $store = makeYouCanStoreForSync();

    // The whole point: before this, a revoked token left the store reading
    // "Connected" while silently ingesting nothing.
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    try {
        app(ProductSyncService::class)->syncStore($store);
    } catch (Throwable) {
        // The exception still propagates for the caller's retry handling;
        // this test is about the side effect on the store.
    }

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::FAILED);
});

test('a platform outage during a product sync leaves the store connected', function () {
    $store = makeYouCanStoreForSync();

    Http::fake(['*' => Http::response(['message' => 'Server error'], 500)]);

    try {
        app(ProductSyncService::class)->syncStore($store);
    } catch (Throwable) {
        // Expected: transient failures still bubble up to be retried.
    }

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
});
