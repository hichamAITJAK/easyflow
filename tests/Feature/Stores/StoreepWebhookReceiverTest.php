<?php

use App\Enums\StoreConnectionStatus;
use App\Jobs\ProcessStoreepOrderWebhookJob;
use App\Models\EcommercePlatform;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeConnectedStoreepStore(?string $webhookSecret = 'url-secret-abc'): Store
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'Storeep', 'slug' => 'Storeep']);

    return Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'my-storeep-shop',
        'external_store_id' => hash('sha256', 'storeep_test_token'),
        'api_credentials' => json_encode(['access_token' => 'storeep_test_token']),
        'webhook_secret' => $webhookSecret,
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);
}

test('a delivery with the correct url secret dispatches the order-processing job', function () {
    Queue::fake();
    $store = makeConnectedStoreepStore();

    $response = $this->postJson(
        route('webhooks.storeep.create-order', ['store' => $store, 'secret' => 'url-secret-abc']),
        ['id' => 298437652918374563, 'number' => 1042],
    );

    $response->assertNoContent();
    Queue::assertPushed(ProcessStoreepOrderWebhookJob::class, fn ($job) => $job->store->is($store)
        && $job->payload['id'] === 298437652918374563
    );
});

test('a delivery with a wrong url secret is rejected without dispatching a job', function () {
    Queue::fake();
    $store = makeConnectedStoreepStore();

    $response = $this->postJson(
        route('webhooks.storeep.create-order', ['store' => $store, 'secret' => 'not-the-secret']),
        ['id' => 298437652918374563],
    );

    $response->assertUnauthorized();
    Queue::assertNotPushed(ProcessStoreepOrderWebhookJob::class);
});

test('a store with no webhook secret rejects every delivery', function () {
    Queue::fake();
    // A store whose webhook subscription never succeeded has no secret. An
    // empty expected secret must never authenticate an empty supplied one.
    $store = makeConnectedStoreepStore(webhookSecret: null);

    $response = $this->postJson(
        route('webhooks.storeep.create-order', ['store' => $store, 'secret' => 'anything']),
        ['id' => 298437652918374563],
    );

    $response->assertUnauthorized();
    Queue::assertNotPushed(ProcessStoreepOrderWebhookJob::class);
});

test('one store\'s webhook secret does not authenticate another store\'s endpoint', function () {
    Queue::fake();
    $storeA = makeConnectedStoreepStore(webhookSecret: 'secret-for-a');
    $storeB = makeConnectedStoreepStore(webhookSecret: 'secret-for-b');

    $response = $this->postJson(
        route('webhooks.storeep.create-order', ['store' => $storeB, 'secret' => 'secret-for-a']),
        ['id' => 298437652918374563],
    );

    $response->assertUnauthorized();
    Queue::assertNotPushed(ProcessStoreepOrderWebhookJob::class);
    expect($storeA->webhook_secret)->toBe('secret-for-a');
});
