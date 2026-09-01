<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Models\User;
use App\Services\Operations\EcomPlatforms\StoreepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: Store, 1: User}
 */
function makeStoreepStoreForSubscription(): array
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'Storeep', 'slug' => 'Storeep']);

    $store = Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'my-storeep-shop',
        'external_store_id' => hash('sha256', 'storeep_test_token'),
        'api_credentials' => json_encode(['access_token' => 'storeep_test_token']),
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    return [$store, $user];
}

test('registering persists the webhook id and a per-store url secret', function () {
    [$store, $admin] = makeStoreepStoreForSubscription();

    Http::fake([
        'api.storeep.com/v1/webhooks*' => Http::sequence()
            ->push(['data' => []], 200)
            ->push(['status' => 'success', 'data' => ['id' => 298437652918374561]], 201),
    ]);

    (new StoreepService($store))->registerOrderWebhook();

    $store->refresh();
    expect($store->meta['storeep_webhook_id'])->toBe(298437652918374561);
    expect($store->webhook_secret)->not->toBeEmpty();
});

test('the registered callback url carries the store\'s own secret', function () {
    [$store, $admin] = makeStoreepStoreForSubscription();

    Http::fake([
        'api.storeep.com/v1/webhooks*' => Http::sequence()
            ->push(['data' => []], 200)
            ->push(['status' => 'success', 'data' => ['id' => 1]], 201),
    ]);

    (new StoreepService($store))->registerOrderWebhook();

    $store->refresh();
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && ($request->data()['event'] ?? null) === 'order-created'
        && ($request->data()['format'] ?? null) === 'json'
        && str_contains((string) ($request->data()['url_or_email'] ?? ''), $store->webhook_secret)
    );
});

test('registering logs a warning and leaves meta untouched on failure', function () {
    [$store, $admin] = makeStoreepStoreForSubscription();

    Http::fake([
        'api.storeep.com/v1/webhooks*' => Http::sequence()
            ->push(['data' => []], 200)
            ->push(['status' => 'error', 'errors' => ['maximum of 20 webhooks per store reached']], 422),
    ]);

    (new StoreepService($store))->registerOrderWebhook();

    $store->refresh();
    expect($store->meta['storeep_webhook_id'] ?? null)->toBeNull();
});

test('registering first clears our own previous order-created webhook', function () {
    [$store, $admin] = makeStoreepStoreForSubscription();

    Http::fake([
        'api.storeep.com/v1/webhooks*' => Http::sequence()
            ->push(['data' => [[
                'id' => 111,
                'event' => 'order-created',
                'url_or_email' => 'https://easyflow.test/webhooks/storeep/1/create-order/old-secret',
            ]]], 200)
            ->push(['status' => 'success'], 200)
            ->push(['status' => 'success', 'data' => ['id' => 222]], 201),
    ]);

    (new StoreepService($store))->registerOrderWebhook();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'id=111')
    );
});

test('a merchant\'s own order-created webhook pointing elsewhere is left alone', function () {
    [$store, $admin] = makeStoreepStoreForSubscription();

    Http::fake([
        'api.storeep.com/v1/webhooks*' => Http::sequence()
            ->push(['data' => [[
                'id' => 999,
                'event' => 'order-created',
                'url_or_email' => 'https://merchants-own-crm.example.com/hook',
            ]]], 200)
            ->push(['status' => 'success', 'data' => ['id' => 222]], 201),
    ]);

    (new StoreepService($store))->registerOrderWebhook();

    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
});

test('deregistering uses the persisted webhook id', function () {
    [$store, $admin] = makeStoreepStoreForSubscription();
    $store->update(['meta' => ['storeep_webhook_id' => 298437652918374561]]);

    Http::fake(['api.storeep.com/*' => Http::response(['status' => 'success'], 200)]);

    (new StoreepService($store))->deregisterOrderWebhook();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'id=298437652918374561')
    );
});

test('deregistering no-ops when no webhook id was ever persisted', function () {
    [$store, $admin] = makeStoreepStoreForSubscription();

    Http::fake();

    (new StoreepService($store))->deregisterOrderWebhook();

    Http::assertNothingSent();
});

test('deleting a store unsubscribes its webhook before the row is removed', function () {
    [$store, $admin] = makeStoreepStoreForSubscription();
    $store->update(['meta' => ['storeep_webhook_id' => 298437652918374561]]);

    Http::fake(['api.storeep.com/*' => Http::response(['status' => 'success'], 200)]);

    // Goes through the controller, which is what dispatches StoreDeleting —
    // deleting the model directly would skip the listener entirely.
    $this->actingAs($admin)->delete(route('stores.destroy', $store), ['confirmation' => $store->name]);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'id=298437652918374561')
    );
    expect(Store::find($store->id))->toBeNull();
});
