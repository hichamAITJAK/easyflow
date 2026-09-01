<?php

use App\Enums\StoreConnectionStatus;
use App\Jobs\Shopify\HandleCustomerDataRequestJob;
use App\Jobs\Shopify\HandleCustomerRedactJob;
use App\Jobs\Shopify\HandleShopAppUninstalledJob;
use App\Jobs\Shopify\HandleShopRedactJob;
use App\Models\Customer;
use App\Models\EcommercePlatform;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeComplianceShopifyStore(): Store
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

/**
 * POST a compliance webhook, signing the body unless $hmac is overridden.
 */
function postComplianceWebhook(string $routeName, array $body, ?string $hmac = null, string $shopDomain = 'my-shop.myshopify.com')
{
    $payload = json_encode($body);

    return test()->call(
        'POST',
        route($routeName),
        [], [], [],
        [
            'HTTP_X_Shopify_Hmac_Sha256' => $hmac ?? base64_encode(hash_hmac('sha256', $payload, 'secret-abc', true)),
            'HTTP_X_Shopify_Shop_Domain' => $shopDomain,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    );
}

beforeEach(function () {
    config(['services.shopify.client_secret' => 'secret-abc']);
});

test('each compliance webhook queues its job when the signature is valid', function (string $routeName, string $jobClass) {
    Queue::fake();
    makeComplianceShopifyStore();

    postComplianceWebhook($routeName, ['shop_domain' => 'my-shop.myshopify.com'])
        ->assertOk();

    Queue::assertPushed($jobClass);
})->with([
    ['webhooks.shopify.customers.data-request', HandleCustomerDataRequestJob::class],
    ['webhooks.shopify.customers.redact', HandleCustomerRedactJob::class],
    ['webhooks.shopify.shop.redact', HandleShopRedactJob::class],
    ['webhooks.shopify.app.uninstalled', HandleShopAppUninstalledJob::class],
]);

// Shopify explicitly probes each compliance endpoint with a bad signature
// during app review and rejects apps that answer 2xx.
test('each compliance webhook rejects an invalid signature with 401', function (string $routeName, string $jobClass) {
    Queue::fake();
    makeComplianceShopifyStore();

    postComplianceWebhook($routeName, ['shop_domain' => 'my-shop.myshopify.com'], 'not-valid')
        ->assertUnauthorized();

    Queue::assertNotPushed($jobClass);
})->with([
    ['webhooks.shopify.customers.data-request', HandleCustomerDataRequestJob::class],
    ['webhooks.shopify.customers.redact', HandleCustomerRedactJob::class],
    ['webhooks.shopify.shop.redact', HandleShopRedactJob::class],
    ['webhooks.shopify.app.uninstalled', HandleShopAppUninstalledJob::class],
]);

test('shop/redact is accepted even when the store no longer exists', function () {
    Queue::fake();

    postComplianceWebhook('webhooks.shopify.shop.redact', ['shop_domain' => 'gone.myshopify.com'], null, 'gone.myshopify.com')
        ->assertOk();

    Queue::assertPushed(HandleShopRedactJob::class, fn ($job) => $job->store === null);
});

test('customers/redact nulls the PII on matching orders but keeps the row', function () {
    $store = makeComplianceShopifyStore();
    $phone = '+212600000000';

    $order = Order::create([
        'reference' => 'ORD-1',
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'external_order_id' => '999',
        'source_platform' => 'Shopify',
        'customer_name' => 'Redact Me',
        'customer_phone' => $phone,
        'customer_phone_hash' => hash('sha256', $phone),
        'customer_address' => '1 Rue Test',
        'total_amount' => 250,
        'confirmation_status' => 'new',
    ]);

    (new HandleCustomerRedactJob($store, ['customer' => ['id' => 1, 'phone' => $phone]]))->handle();

    $order->refresh();

    expect($order->exists)->toBeTrue()
        ->and($order->customer_name)->toBeNull()
        ->and($order->customer_phone)->toBeNull()
        ->and($order->customer_address)->toBeNull()
        ->and($order->customer_phone_hash)->toBeNull()
        // Non-personal fields must survive: the commission ledger and daily
        // stats reference these orders.
        ->and((float) $order->total_amount)->toBe(250.0);
});

test('shop/redact strips PII across the store and clears its credentials', function () {
    $store = makeComplianceShopifyStore();
    $phone = '+212611111111';

    $order = Order::create([
        'reference' => 'ORD-2',
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'external_order_id' => '1000',
        'source_platform' => 'Shopify',
        'customer_name' => 'Shop Redact',
        'customer_phone' => $phone,
        'customer_phone_hash' => hash('sha256', $phone),
        'customer_address' => '2 Rue Test',
        'total_amount' => 100,
        'confirmation_status' => 'new',
    ]);

    $customer = Customer::create([
        'business_id' => $store->business_id,
        'name' => 'Shop Redact',
        'phone' => $phone,
        'phone_hash' => hash('sha256', $phone),
        'address' => '2 Rue Test',
    ]);

    (new HandleShopRedactJob($store, ['shop_domain' => 'my-shop.myshopify.com']))->handle();

    $order->refresh();
    $customer->refresh();
    $store->refresh();

    expect($order->customer_name)->toBeNull()
        ->and($order->customer_phone_hash)->toBeNull()
        ->and($customer->phone)->toBeNull()
        ->and($customer->phone_hash)->toBeNull()
        ->and($store->api_credentials)->toBeNull();
});

test('app/uninstalled disconnects the store and drops the dead token', function () {
    $store = makeComplianceShopifyStore();

    (new HandleShopAppUninstalledJob($store))->handle();

    $store->refresh();

    expect($store->connection_status)->toBe(StoreConnectionStatus::FAILED)
        ->and($store->api_credentials)->toBeNull();
});
