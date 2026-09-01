<?php

use App\Enums\StoreConnectionStatus;
use App\Jobs\ProcessShopifyOrderWebhookJob;
use App\Models\EcommercePlatform;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeConnectedShopifyStore(): Store
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'Shopify', 'slug' => 'Shopify']);

    return Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'my-shop.myshopify.com',
        'external_store_id' => 'my-shop.myshopify.com',
        'api_credentials' => 'shpat_test',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);
}

test('a valid webhook signature dispatches the order-processing job with the raw payload', function () {
    Queue::fake();
    config(['services.shopify.client_secret' => 'secret-abc']);
    $store = makeConnectedShopifyStore();

    $payload = json_encode(['id' => 123456, 'financial_status' => 'paid']);
    $hmac = base64_encode(hash_hmac('sha256', $payload, 'secret-abc', true));

    $response = $this->call(
        'POST',
        route('webhooks.shopify.receive', $store),
        [],
        [],
        [],
        ['HTTP_X_Shopify_Hmac_Sha256' => $hmac, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $response->assertOk();
    Queue::assertPushed(ProcessShopifyOrderWebhookJob::class, fn ($job) => $job->store->is($store)
        && $job->payload['id'] === 123456
    );
});

test('an invalid webhook signature is rejected without dispatching a job', function () {
    Queue::fake();
    config(['services.shopify.client_secret' => 'secret-abc']);
    $store = makeConnectedShopifyStore();

    $payload = json_encode(['id' => 123456]);

    $response = $this->call(
        'POST',
        route('webhooks.shopify.receive', $store),
        [],
        [],
        [],
        ['HTTP_X_Shopify_Hmac_Sha256' => 'not-valid', 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $response->assertUnauthorized();
    Queue::assertNotPushed(ProcessShopifyOrderWebhookJob::class);
});
