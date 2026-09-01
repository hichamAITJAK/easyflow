<?php

use App\Enums\StoreConnectionStatus;
use App\Jobs\ProcessShopifyOrderWebhookJob;
use App\Jobs\ProcessYouCanOrderWebhookJob;
use App\Jobs\SyncStoreProductsJob;
use App\Models\EcommercePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Services\Operations\Orders\OrderSyncService;
use App\Services\Operations\Products\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeStoreForPlatform(string $slug): Store
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => $slug, 'slug' => $slug]);

    return Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'Store '.uniqid(),
        'api_credentials' => 'token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);
}

test('ProcessShopifyOrderWebhookJob creates an order when the store already has synced products', function () {
    Queue::fake();
    $store = makeStoreForPlatform('Shopify');
    Product::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'gid://shopify/Product/1',
        'name' => 'Widget',
    ]);

    $job = new ProcessShopifyOrderWebhookJob($store, [
        'id' => 'gid://shopify/Order/1',
        'name' => '#1001',
        'createdAt' => '2024-01-15T10:30:00Z',
        'customer' => ['firstName' => 'Jane', 'lastName' => 'Doe', 'phone' => '+212600000000'],
        'shippingAddress' => ['address1' => '123 Main St', 'city' => 'Casablanca'],
    ]);
    $job->withFakeQueueInteractions();

    $job->handle(app(OrderSyncService::class));

    $job->assertNotReleased();
    expect(Order::where('store_id', $store->id)->where('external_order_id', 'gid://shopify/Order/1')->exists())
        ->toBeTrue();
    Queue::assertNotPushed(SyncStoreProductsJob::class);
});

test('ProcessShopifyOrderWebhookJob syncs products and releases itself when none exist yet', function () {
    Queue::fake();
    $store = makeStoreForPlatform('Shopify');

    $job = new ProcessShopifyOrderWebhookJob($store, [
        'id' => 'gid://shopify/Order/1',
        'name' => '#1001',
    ]);
    $job->withFakeQueueInteractions();

    $job->handle(app(OrderSyncService::class));

    $job->assertReleased(300);
    Queue::assertPushed(SyncStoreProductsJob::class, fn ($pushed) => $pushed->store->is($store));
    expect(Order::where('store_id', $store->id)->exists())->toBeFalse();
});

test('ProcessYouCanOrderWebhookJob creates an order when the store already has synced products', function () {
    Queue::fake();
    $store = makeStoreForPlatform('YouCan');
    Product::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'prod-1',
        'name' => 'Widget',
    ]);

    $job = new ProcessYouCanOrderWebhookJob($store, [
        'id' => '2250ad72-45b5-11e9-b473-080027e8bf1b',
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'shipping_address' => ['first_line' => '123 Main St', 'city' => 'Casablanca'],
        'created_at' => 1552497987,
    ]);
    $job->withFakeQueueInteractions();

    $job->handle(app(OrderSyncService::class));

    $job->assertNotReleased();
    expect(Order::where('store_id', $store->id)->where('external_order_id', '2250ad72-45b5-11e9-b473-080027e8bf1b')->exists())
        ->toBeTrue();
    Queue::assertNotPushed(SyncStoreProductsJob::class);
});

test('ProcessYouCanOrderWebhookJob syncs products and releases itself when none exist yet', function () {
    Queue::fake();
    $store = makeStoreForPlatform('YouCan');

    $job = new ProcessYouCanOrderWebhookJob($store, [
        'id' => '2250ad72-45b5-11e9-b473-080027e8bf1b',
    ]);
    $job->withFakeQueueInteractions();

    $job->handle(app(OrderSyncService::class));

    $job->assertReleased(300);
    Queue::assertPushed(SyncStoreProductsJob::class, fn ($pushed) => $pushed->store->is($store));
    expect(Order::where('store_id', $store->id)->exists())->toBeFalse();
});

test('SyncStoreProductsJob delegates to ProductSyncService for the given store', function () {
    $store = makeStoreForPlatform('Shopify');

    $productSync = Mockery::mock(ProductSyncService::class);
    $productSync->shouldReceive('syncStore')->once()->with(Mockery::on(fn ($arg) => $arg->is($store)));

    (new SyncStoreProductsJob($store))->handle($productSync);
});

test('re-syncing an order sets confirmation_status to new only on first insert', function () {
    $store = makeStoreForPlatform('Shopify');
    $payload = [
        'id' => 'gid://shopify/Order/1',
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'shipping_address' => ['first_line' => '123 Main St', 'city' => 'Casablanca'],
        'total' => 100,
    ];

    $order = app(OrderSyncService::class)->syncOne($store, $payload);

    expect($order->confirmation_status->value)->toBe('new');

    $order->update(['confirmation_status' => 'confirmed']);

    $resynced = app(OrderSyncService::class)->syncOne($store, [
        ...$payload,
        'total' => 150,
    ]);

    expect($resynced->id)->toBe($order->id);
    expect($resynced->confirmation_status->value)->toBe('confirmed');
    expect((float) $resynced->total_amount)->toBe(150.0);
});
