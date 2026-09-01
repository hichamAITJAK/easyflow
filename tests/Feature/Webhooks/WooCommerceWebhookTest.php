<?php

use App\Jobs\ProcessWooCommerceOrderWebhookJob;
use App\Models\EcommercePlatform;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeWooWebhookStore(string $secret = 'test-webhook-secret'): Store
{
    $admin = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'WooCommerce', 'slug' => 'WooCommerce']);

    return Store::create([
        'business_id' => $admin->business_id,
        'platform_id' => $platform->id,
        'external_store_id' => 'https://shop.example.com',
        'name' => 'Example shop',
        'slug' => 'example-shop',
        'connection_status' => 'connected',
        'webhook_secret' => $secret,
        'api_credentials' => json_encode([
            'store_url' => 'https://shop.example.com',
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]),
    ]);
}

/**
 * WooCommerce signs the RAW body with HMAC-SHA256 and sends it base64
 * encoded — not hex, as YouCan does.
 */
function wooSignature(string $body, string $secret): string
{
    return base64_encode(hash_hmac('sha256', $body, $secret, true));
}

test('a correctly signed delivery is queued', function () {
    Queue::fake();

    $store = makeWooWebhookStore();
    $body = json_encode(['id' => 727, 'status' => 'processing', 'total' => '529.00']);

    $this->call(
        'POST',
        route('webhooks.woocommerce.create-order', $store),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => wooSignature($body, 'test-webhook-secret'),
        ],
        content: $body,
    )->assertNoContent();

    Queue::assertPushed(ProcessWooCommerceOrderWebhookJob::class);
});

test('a delivery with a bad signature is rejected', function () {
    Queue::fake();

    $store = makeWooWebhookStore();
    $body = json_encode(['id' => 727]);

    $this->call(
        'POST',
        route('webhooks.woocommerce.create-order', $store),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => wooSignature($body, 'the-wrong-secret'),
        ],
        content: $body,
    )->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('a delivery with no signature is rejected', function () {
    Queue::fake();

    $store = makeWooWebhookStore();

    $this->call(
        'POST',
        route('webhooks.woocommerce.create-order', $store),
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['id' => 727]),
    )->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('a store with no webhook secret rejects every delivery', function () {
    // An empty stored secret must never be treated as "signature matches
    // the empty key" — that would accept anything.
    Queue::fake();

    $store = makeWooWebhookStore(secret: '');
    $body = json_encode(['id' => 727]);

    $this->call(
        'POST',
        route('webhooks.woocommerce.create-order', $store),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => wooSignature($body, ''),
        ],
        content: $body,
    )->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('the signature is computed over the raw body, not a re-encoding', function () {
    // Re-encoding the decoded JSON would change key order and spacing, so a
    // body whose formatting differs from canonical json_encode must still
    // verify.
    Queue::fake();

    $store = makeWooWebhookStore();
    $body = '{"id":  727,   "status": "processing"}';

    $this->call(
        'POST',
        route('webhooks.woocommerce.create-order', $store),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => wooSignature($body, 'test-webhook-secret'),
        ],
        content: $body,
    )->assertNoContent();

    Queue::assertPushed(ProcessWooCommerceOrderWebhookJob::class);
});

test('the delivery-check ping is acknowledged but not queued as an order', function () {
    // WooCommerce pings a newly created webhook to confirm the endpoint is
    // reachable. It is signed like any delivery, but it is not an order.
    Queue::fake();

    $store = makeWooWebhookStore();
    $body = json_encode(['webhook_id' => 12, 'id' => 12]);

    $this->call(
        'POST',
        route('webhooks.woocommerce.create-order', $store),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => wooSignature($body, 'test-webhook-secret'),
        ],
        content: $body,
    )->assertNoContent();

    Queue::assertNothingPushed();
});
