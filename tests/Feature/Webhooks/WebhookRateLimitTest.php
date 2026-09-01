<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * PRD: inbound webhooks are rate-limited "per tenant/per store, not just per
 * IP". Every delivery from one platform shares that platform's egress
 * addresses, so a per-IP limit would let one busy store throttle every other
 * store behind the same addresses.
 */
function makeShopifyStoreFor(?int $businessId = null): Store
{
    $businessId ??= makeBusinessUser()->business_id;
    $platform = EcommercePlatform::firstOrCreate(
        ['slug' => 'shopify-rl'],
        ['name' => 'Shopify'],
    );

    return Store::create([
        'business_id' => $businessId,
        'platform_id' => $platform->id,
        'name' => 'shop-'.uniqid().'.myshopify.com',
        'external_store_id' => 'shop-'.uniqid().'.myshopify.com',
        'api_credentials' => 'shpat_test',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);
}

function postShopifyWebhook(Store $store, string $secret = 'secret-abc'): TestResponse
{
    $payload = json_encode(['id' => uniqid(), 'financial_status' => 'paid']);

    return test()->call(
        'POST',
        route('webhooks.shopify.receive', $store),
        [], [], [],
        [
            'HTTP_X_Shopify_Hmac_Sha256' => base64_encode(hash_hmac('sha256', $payload, $secret, true)),
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    );
}

beforeEach(function () {
    Queue::fake();
    config(['services.shopify.client_secret' => 'secret-abc']);
    // Buckets are per-key and survive within a test run, so start clean.
    cache()->flush();
});

/**
 * Drive real requests up to the ceiling.
 *
 * ThrottleRequests hashes the limiter key internally, so seeding the bucket
 * with RateLimiter::hit('store:7') writes somewhere the middleware never
 * reads — the throttle would look broken while actually working. The only
 * honest way to fill it is through the middleware itself.
 */
function drainStoreLimit(Store $store, int $times = 300): void
{
    for ($i = 0; $i < $times; $i++) {
        postShopifyWebhook($store);
    }
}

test('the order webhook is throttled once a store floods it', function () {
    $store = makeShopifyStoreFor();

    drainStoreLimit($store);

    postShopifyWebhook($store)->assertStatus(429);
});

test('one store flooding does not throttle another store', function () {
    $noisy = makeShopifyStoreFor();
    $quiet = makeShopifyStoreFor();

    drainStoreLimit($noisy);

    // The whole point of keying per store rather than per IP: both stores
    // deliver from the same platform egress addresses, so a per-IP limit
    // would have taken the quiet store down with the noisy one.
    postShopifyWebhook($noisy)->assertStatus(429);
    postShopifyWebhook($quiet)->assertOk();
});

test('ordinary webhook traffic is not throttled', function () {
    $store = makeShopifyStoreFor();

    // A burst well inside the ceiling must pass untouched — a limit that
    // trips on normal volume would make platforms retry real orders.
    for ($i = 0; $i < 15; $i++) {
        postShopifyWebhook($store)->assertOk();
    }
});

test('a throttled webhook answers with clean JSON, not an HTML error page', function () {
    $store = makeShopifyStoreFor();

    drainStoreLimit($store);

    $response = postShopifyWebhook($store);

    // Webhook senders parse responses. An Inertia HTML error page here
    // would be a 12KB body for a machine that wants a status code, and
    // Shopify's automated checks reject exactly that shape.
    $response->assertStatus(429);
    expect($response->headers->get('Content-Type'))->toContain('json');
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

test('the compliance webhooks are throttled by IP, not left open', function () {
    // These carry no {store} — they are shop-scoped and resolved from a
    // header — so IP is the only key available.
    for ($i = 0; $i < 60; $i++) {
        $this->postJson(route('webhooks.shopify.shop.redact'), ['shop_domain' => 'x.myshopify.com']);
    }

    $this->postJson(route('webhooks.shopify.shop.redact'), ['shop_domain' => 'x.myshopify.com'])
        ->assertStatus(429);
});

test('the compliance limit stays clear of shopify review checks', function () {
    // Shopify's automated app review POSTs a handful of times. Answering one
    // of those with a 429 fails the review, so a small burst must pass.
    for ($i = 0; $i < 10; $i++) {
        expect(
            $this->postJson(route('webhooks.shopify.shop.redact'), ['shop_domain' => 'x.myshopify.com'])
                ->getStatusCode()
        )->not->toBe(429);
    }
});
