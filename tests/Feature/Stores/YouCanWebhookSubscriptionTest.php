<?php

use App\Enums\StoreConnectionStatus;
use App\Events\Store\StoreDeleting;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Models\User;
use App\Services\Operations\EcomPlatforms\YouCanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: Store, 1: User} the store and its owning (admin) user
 */
function makeConnectedYouCanStoreWithCredentials(array $overrides = []): array
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'YouCan', 'slug' => 'YouCan']);

    $store = Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'my-shop',
        'external_store_id' => 'my-shop',
        'api_credentials' => json_encode(['access_token' => 'ACCESS-1']),
        'connection_status' => StoreConnectionStatus::CONNECTED,
        ...$overrides,
    ]);

    return [$store, $user];
}

test('registerOrderWebhook persists the returned subscription id to meta', function () {
    [$store] = makeConnectedYouCanStoreWithCredentials();

    Http::fake([
        'api.youcan.shop/resthooks/subscribe' => Http::response(['id' => 'resthook-abc-123'], 200),
    ]);

    (new YouCanService($store))->registerOrderWebhook();

    expect($store->refresh()->meta['youcan_resthook_id'])->toBe('resthook-abc-123');
});

test('registerOrderWebhook logs a warning and leaves meta untouched on failure', function () {
    [$store] = makeConnectedYouCanStoreWithCredentials();

    Http::fake([
        'api.youcan.shop/resthooks/subscribe' => Http::response(['message' => 'error'], 500),
    ]);

    (new YouCanService($store))->registerOrderWebhook();

    expect($store->refresh()->meta['youcan_resthook_id'] ?? null)->toBeNull();
});

test('deregisterOrderWebhook unsubscribes using the persisted subscription id', function () {
    [$store] = makeConnectedYouCanStoreWithCredentials(['meta' => ['youcan_resthook_id' => 'resthook-abc-123']]);

    Http::fake([
        'api.youcan.shop/resthooks/unsubscribe/resthook-abc-123' => Http::response([], 200),
    ]);

    (new YouCanService($store))->deregisterOrderWebhook();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.youcan.shop/resthooks/unsubscribe/resthook-abc-123');
});

test('deregisterOrderWebhook no-ops when no subscription id was ever persisted', function () {
    [$store] = makeConnectedYouCanStoreWithCredentials();

    Http::fake();

    (new YouCanService($store))->deregisterOrderWebhook();

    Http::assertNothingSent();
});

test('deleting a store fires StoreDeleting before the row is removed', function () {
    Event::fake([StoreDeleting::class]);

    [$store, $admin] = makeConnectedYouCanStoreWithCredentials();

    $this->actingAs($admin)->delete(route('stores.destroy', $store), ['confirmation' => $store->name]);

    Event::assertDispatched(StoreDeleting::class, fn ($event) => $event->store->is($store));
    expect(Store::find($store->id))->toBeNull();
});

test('deleting a store actually unsubscribes its webhook before deletion', function () {
    [$store, $admin] = makeConnectedYouCanStoreWithCredentials(['meta' => ['youcan_resthook_id' => 'resthook-abc-123']]);

    Http::fake([
        'api.youcan.shop/resthooks/unsubscribe/resthook-abc-123' => Http::response([], 200),
    ]);

    $this->actingAs($admin)->delete(route('stores.destroy', $store), ['confirmation' => $store->name]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.youcan.shop/resthooks/unsubscribe/resthook-abc-123');
    expect(Store::find($store->id))->toBeNull();
});
