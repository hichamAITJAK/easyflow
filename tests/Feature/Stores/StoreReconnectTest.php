<?php

use App\Enums\StoreConnectionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\CreatesConnectedStore;
use App\Models\EcommercePlatform;
use App\Models\Scopes\BusinessScope;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Exercises the trait directly — every platform callback funnels through it. */
function connector(): object
{
    return new class
    {
        use CreatesConnectedStore;

        /** @param array<string, mixed> $attributes */
        public function run(array $attributes): mixed
        {
            return $this->createStoreOrRedirectBack($attributes);
        }
    };
}

/** @return array{EcommercePlatform, int} */
function platformAndBusiness(): array
{
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $platform = EcommercePlatform::create(['name' => 'YouCan', 'slug' => 'YouCan-'.uniqid()]);

    return [$platform, $admin->business_id];
}

test('reconnecting an existing store refreshes it in place instead of duplicating', function () {
    [$platform, $businessId] = platformAndBusiness();

    $first = connector()->run([
        'business_id' => $businessId,
        'platform_id' => $platform->id,
        'external_store_id' => 'ext-1',
        'name' => 'Acme Shop',
        'api_credentials' => 'old-token',
        'connection_status' => StoreConnectionStatus::FAILED,
    ]);

    $second = connector()->run([
        'business_id' => $businessId,
        'platform_id' => $platform->id,
        'external_store_id' => 'ext-1',
        'name' => 'Acme Shop Renamed On Platform',
        'api_credentials' => 'new-token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    expect($second)->toBeInstanceOf(Store::class)
        ->and($second->id)->toBe($first->id)
        ->and(Store::where('business_id', $businessId)->count())->toBe(1);

    $fresh = $second->fresh();

    expect($fresh->api_credentials)->toBe('new-token')
        ->and($fresh->connection_status)->toBe(StoreConnectionStatus::CONNECTED)
        // The merchant's own name is preserved: the slug rides in webhook
        // URLs and the name identifies the store throughout the app.
        ->and($fresh->name)->toBe('Acme Shop');
});

test('reconnecting keeps the store id so its products and orders survive', function () {
    [$platform, $businessId] = platformAndBusiness();

    $store = connector()->run([
        'business_id' => $businessId,
        'platform_id' => $platform->id,
        'external_store_id' => 'ext-2',
        'name' => 'Acme Shop',
        'api_credentials' => 'old-token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    $order = makeOrder($businessId, ['store_id' => $store->id]);

    connector()->run([
        'business_id' => $businessId,
        'platform_id' => $platform->id,
        'external_store_id' => 'ext-2',
        'name' => 'Acme Shop',
        'api_credentials' => 'new-token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    expect($order->fresh()->store_id)->toBe($store->id);
});

test('another business connecting the same external store does not take it over', function () {
    [$platform, $businessId] = platformAndBusiness();
    $intruderAdmin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $original = connector()->run([
        'business_id' => $businessId,
        'platform_id' => $platform->id,
        'external_store_id' => 'ext-3',
        'name' => 'Acme Shop',
        'api_credentials' => 'owner-token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    $result = connector()->run([
        'business_id' => $intruderAdmin->business_id,
        'platform_id' => $platform->id,
        'external_store_id' => 'ext-3',
        'name' => 'Stolen Shop',
        'api_credentials' => 'intruder-token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    // Not a Store: the unique index rejects it and it becomes a redirect.
    expect($result)->not->toBeInstanceOf(Store::class);

    $fresh = Store::withoutGlobalScope(BusinessScope::class)->find($original->id);

    expect($fresh->business_id)->toBe($businessId)
        ->and($fresh->api_credentials)->toBe('owner-token');
});
