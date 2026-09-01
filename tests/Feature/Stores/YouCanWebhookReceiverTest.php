<?php

use App\Enums\StoreConnectionStatus;
use App\Jobs\ProcessYouCanOrderWebhookJob;
use App\Models\EcommercePlatform;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeConnectedYouCanStore(): Store
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'YouCan', 'slug' => 'YouCan']);

    return Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'my-shop',
        'external_store_id' => 'my-shop',
        'api_credentials' => 'youcan_test_token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);
}

test('a valid webhook signature dispatches the order-processing job with the raw payload', function () {
    Queue::fake();
    config(['services.youcan.client_secret' => 'secret-abc']);
    $store = makeConnectedYouCanStore();

    $payload = ['id' => '2250ad72-45b5-11e9-b473-080027e8bf1b', 'status' => 1];
    $hmac = hash_hmac('sha256', json_encode($payload), 'secret-abc');

    $response = $this->call(
        'POST',
        route('webhooks.youcan.create-order', $store),
        [],
        [],
        [],
        ['HTTP_X_Youcan_Signature' => $hmac, 'CONTENT_TYPE' => 'application/json'],
        json_encode($payload),
    );

    $response->assertNoContent();
    Queue::assertPushed(ProcessYouCanOrderWebhookJob::class, fn ($job) => $job->store->is($store)
        && $job->payload['id'] === $payload['id']
    );
});

test('a valid signature over raw unicode payload bytes is accepted', function () {
    Queue::fake();
    config(['services.youcan.client_secret' => 'secret-abc']);
    $store = makeConnectedYouCanStore();

    // Raw body as YouCan would send it: unescaped unicode and slashes.
    // json_encode()'ing the decoded array would re-escape these bytes
    // differently, which previously broke the signature check.
    $rawBody = '{"id":"2250ad72-45b5-11e9-b473-080027e8bf1b","city":"Casablanca-Settat,","url":"https://steine.youcan.store/products/product-5"}';
    $hmac = hash_hmac('sha256', $rawBody, 'secret-abc');

    $response = $this->call(
        'POST',
        route('webhooks.youcan.create-order', $store),
        [],
        [],
        [],
        ['HTTP_X_Youcan_Signature' => $hmac, 'CONTENT_TYPE' => 'application/json'],
        $rawBody,
    );

    $response->assertNoContent();
    Queue::assertPushed(ProcessYouCanOrderWebhookJob::class, fn ($job) => $job->store->is($store));
});

test('an invalid webhook signature is rejected without dispatching a job', function () {
    Queue::fake();
    config(['services.youcan.client_secret' => 'secret-abc']);
    $store = makeConnectedYouCanStore();

    $payload = ['id' => '2250ad72-45b5-11e9-b473-080027e8bf1b'];

    $response = $this->call(
        'POST',
        route('webhooks.youcan.create-order', $store),
        [],
        [],
        [],
        ['HTTP_X_Youcan_Signature' => 'not-valid', 'CONTENT_TYPE' => 'application/json'],
        json_encode($payload),
    );

    $response->assertUnauthorized();
    Queue::assertNotPushed(ProcessYouCanOrderWebhookJob::class);
});
