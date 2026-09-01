<?php

use App\Enums\StoreConnectionStatus;
use App\Jobs\ProcessStoreepOrderWebhookJob;
use App\Models\EcommercePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Services\Operations\Orders\OrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeStoreepStoreWithProduct(): Store
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'Storeep', 'slug' => 'Storeep']);

    $store = Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'my-storeep-shop',
        'external_store_id' => hash('sha256', 'storeep_test_token'),
        'api_credentials' => json_encode(['access_token' => 'storeep_test_token']),
        'webhook_secret' => 'url-secret-abc',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    // The job releases itself back onto the queue until the store has
    // products, so every test here needs at least one.
    Product::create([
        'business_id' => $user->business_id,
        'store_id' => $store->id,
        'external_product_id' => '298437652918374561',
        'name' => 'Classic Cotton T-Shirt',
        'price' => 29.99,
    ]);

    return $store;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storeepApiOrder(array $overrides = []): array
{
    return [
        'id' => 298437652918374563,
        'number' => 1042,
        'total' => 59.97,
        'shipping' => 4.99,
        'currency' => 'USD',
        'market' => 'US',
        'created_at' => '2025-02-10 08:15:00',
        'items' => [[
            'name' => 'Classic Cotton T-Shirt',
            'price' => 29.99,
            'quantity' => 2,
            'sku' => 'SHIRT-M-BLUE',
        ]],
        'addresses' => [[
            'type' => 'shipping',
            'fullname' => 'John Doe',
            'address1' => '123 Main St',
            'city' => 'Los Angeles',
            'phone' => '+1234567890',
        ]],
        ...$overrides,
    ];
}

test('the order is re-fetched from the api rather than built from the delivered payload', function () {
    $store = makeStoreepStoreWithProduct();

    Http::fake([
        'api.storeep.com/v1/orders*' => Http::response([
            'data' => [storeepApiOrder()],
            'meta' => ['pagination' => ['total_pages' => 1]],
        ], 200),
    ]);

    // A forged body claiming a wildly different total and customer. Storeep
    // publishes no webhook signature, so the body is unauthenticated — only
    // the id is used, as a lookup key.
    (new ProcessStoreepOrderWebhookJob($store, [
        'id' => 298437652918374563,
        'total' => 999999.00,
        'addresses' => [['type' => 'shipping', 'fullname' => 'Attacker', 'phone' => '+000']],
    ]))->handle(app(OrderSyncService::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.storeep.com/v1/orders'));

    $order = Order::first();
    expect((float) $order->total_amount)->toBe(59.97);
    expect($order->customer_name)->toBe('John Doe');
});

test('a payload carrying no order id is dropped without calling the api', function () {
    $store = makeStoreepStoreWithProduct();

    Http::fake();

    (new ProcessStoreepOrderWebhookJob($store, ['number' => 1042]))
        ->handle(app(OrderSyncService::class));

    Http::assertNothingSent();
    expect(Order::count())->toBe(0);
});

test('an order id that is not found in the list creates nothing', function () {
    $store = makeStoreepStoreWithProduct();

    Http::fake([
        'api.storeep.com/v1/orders*' => Http::response([
            'data' => [storeepApiOrder(['id' => 111])],
            'meta' => ['pagination' => ['total_pages' => 1]],
        ], 200),
    ]);

    (new ProcessStoreepOrderWebhookJob($store, ['id' => 999]))
        ->handle(app(OrderSyncService::class));

    expect(Order::count())->toBe(0);
});

test('a failed re-fetch is rethrown so the queue retries it', function () {
    $store = makeStoreepStoreWithProduct();

    Http::fake(['api.storeep.com/*' => Http::response(['status' => 'error'], 500)]);

    $job = new ProcessStoreepOrderWebhookJob($store, ['id' => 298437652918374563]);

    // A failed fetch is usually transient (rate limit, timeout) and the
    // order is real and still missing — unlike a malformed payload, it must
    // not be swallowed.
    expect(fn () => $job->handle(app(OrderSyncService::class)))
        ->toThrow(RequestException::class);

    expect(Order::count())->toBe(0);
});

test('the order id is read from a nested order or data envelope', function () {
    $store = makeStoreepStoreWithProduct();

    Http::fake([
        'api.storeep.com/v1/orders*' => Http::response([
            'data' => [storeepApiOrder()],
            'meta' => ['pagination' => ['total_pages' => 1]],
        ], 200),
    ]);

    (new ProcessStoreepOrderWebhookJob($store, ['order' => ['id' => 298437652918374563]]))
        ->handle(app(OrderSyncService::class));

    expect(Order::count())->toBe(1);
});
