<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Services\Operations\Orders\OrderService;
use App\Services\Operations\Orders\OrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeStoreForOrderSync(): Store
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'Shopify', 'slug' => 'shopify-'.uniqid()]);

    return Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'Store '.uniqid(),
        'api_credentials' => 'token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);
}

test('a manual order is auto-flagged test when it contains a test product', function () {
    $admin = makeBusinessUser();
    $product = Product::create([
        'business_id' => $admin->business_id,
        'name' => 'Test Product',
        'is_test' => true,
    ]);

    $order = app(OrderService::class)->createManualOrder($admin->business_id, [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
        'items' => [
            [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 100,
            ],
        ],
    ]);

    expect($order->is_test)->toBeTrue();
});

test('a manual order is not flagged test when its products are all real', function () {
    $admin = makeBusinessUser();
    $product = Product::create([
        'business_id' => $admin->business_id,
        'name' => 'Real Product',
        'is_test' => false,
    ]);

    $order = app(OrderService::class)->createManualOrder($admin->business_id, [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
        'items' => [
            [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 100,
            ],
        ],
    ]);

    expect($order->is_test)->toBeFalse();
});

test('a manual order with no items is not flagged test', function () {
    $admin = makeBusinessUser();

    $order = app(OrderService::class)->createManualOrder($admin->business_id, [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ]);

    expect($order->is_test)->toBeFalse();
});

test('a platform-synced order is auto-flagged test when a line item matches a test product', function () {
    $store = makeStoreForOrderSync();
    Product::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'prod-1',
        'name' => 'Test Product',
        'is_test' => true,
    ]);

    $order = app(OrderSyncService::class)->syncOne($store, [
        'id' => 'order-1',
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'shipping_address' => ['first_line' => '123 Main St', 'city' => 'Casablanca'],
        'total' => 100,
        'line_items' => [
            ['product_id' => 'prod-1', 'title' => 'Test Product', 'quantity' => 1],
        ],
    ]);

    expect($order->is_test)->toBeTrue();
});

test('a platform-synced order is not flagged test when no line item matches a test product', function () {
    $store = makeStoreForOrderSync();
    Product::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'prod-1',
        'name' => 'Real Product',
        'is_test' => false,
    ]);

    $order = app(OrderSyncService::class)->syncOne($store, [
        'id' => 'order-1',
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'shipping_address' => ['first_line' => '123 Main St', 'city' => 'Casablanca'],
        'total' => 100,
        'line_items' => [
            ['product_id' => 'prod-1', 'title' => 'Real Product', 'quantity' => 1],
        ],
    ]);

    expect($order->is_test)->toBeFalse();
});

test('re-syncing an order does not retroactively flag it test if a product is marked test afterward', function () {
    $store = makeStoreForOrderSync();
    $product = Product::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'prod-1',
        'name' => 'Product',
        'is_test' => false,
    ]);

    $payload = [
        'id' => 'order-1',
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'shipping_address' => ['first_line' => '123 Main St', 'city' => 'Casablanca'],
        'total' => 100,
        'line_items' => [
            ['product_id' => 'prod-1', 'title' => 'Product', 'quantity' => 1],
        ],
    ];

    $order = app(OrderSyncService::class)->syncOne($store, $payload);
    expect($order->is_test)->toBeFalse();

    $product->update(['is_test' => true]);

    $resynced = app(OrderSyncService::class)->syncOne($store, $payload);

    expect($resynced->id)->toBe($order->id);
    expect($resynced->is_test)->toBeFalse();
});

test('confirming a test order routes it to test_completed instead of confirmed', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => OrderConfirmationStatus::NEW,
        'is_test' => true,
    ]);

    $updated = app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect($updated->confirmation_status)->toBe(OrderConfirmationStatus::TEST_COMPLETED);
});

test('confirming a real order still reaches confirmed normally', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => OrderConfirmationStatus::NEW,
        'is_test' => false,
    ]);

    $updated = app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect($updated->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
});

test('a test order cannot be shipped to a delivery courier', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => OrderConfirmationStatus::CONFIRMED,
        'is_test' => true,
    ]);

    expect(fn () => app(OrderService::class)->createShipment($order, $admin, [
        'delivery_account_id' => 1,
        'city_id' => 1,
        'customer_name' => $order->customer_name,
        'customer_phone' => $order->customer_phone,
        'customer_address' => $order->customer_address,
        'total_amount' => (float) $order->total_amount,
    ]))->toThrow(InvalidArgumentException::class, 'Test orders cannot be shipped to a delivery courier.');

    expect(Order::find($order->id)->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
});
