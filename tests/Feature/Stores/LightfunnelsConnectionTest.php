<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Services\Operations\EcomPlatforms\LightfunnelsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeLightfunnelsProductsAndOrdersResponses(string $storeId = 'store_1'): array
{
    return [
        'api.lightfunnels.com/*' => Http::response(['access_token' => 'ACCESS-1']),
        'services.lightfunnels.com/*' => Http::sequence()
            // listStores() (resolvePrimaryStore)
            ->push(['data' => ['account' => ['stores' => [
                ['id' => $storeId, 'uid' => 'uid_1', 'name' => 'My LF Store', 'slug' => 'my-lf-store', 'currency' => 'USD', 'defaultDomain' => 'my-lf-store.lfstore.com', 'address' => '123 St', 'legal_name' => 'My LLC', 'email' => 'shop@example.com'],
            ]]]])
            // products()->listProducts() page 1
            ->push(['data' => ['products' => [
                'edges' => [
                    ['node' => [
                        'id' => 'prod_1', '_id' => 1, 'title' => 'Widget', 'slug' => 'widget',
                        'description' => 'A widget', 'price' => 20.0, 'compare_at_price' => 25.0,
                        'sku' => 'W-1', 'inventory_quantity' => 5,
                        'images' => [], 'thumbnail' => null, 'variants' => [], 'tags' => [],
                        'stores' => [['id' => $storeId, 'uid' => 'uid_1']],
                        'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z',
                    ], 'cursor' => 'c1'],
                ],
                'pageInfo' => ['endCursor' => 'c1', 'hasNextPage' => false],
            ]]])
            // orders()->listOrders() page 1
            ->push(['data' => ['orders' => [
                'edges' => [
                    ['node' => [
                        'id' => 'order_1', '_id' => 1, 'name' => '#1001', 'total' => 20.0,
                        'subtotal' => 20.0, 'discount_value' => 0, 'shipping' => 0,
                        'fulfillment_status' => 'unfulfilled', 'financial_status' => 'paid',
                        'email' => 'buyer@example.com', 'phone' => '+1000000000',
                        'customer' => ['id' => 'cus_1', 'full_name' => 'Jane Buyer', 'email' => 'buyer@example.com', 'phone' => '+1000000000'],
                        'shipping_address' => [
                            'first_name' => 'Jane', 'last_name' => 'Buyer', 'line1' => '1 Main St', 'line2' => '',
                            'city' => 'Springfield', 'state' => 'IL', 'country' => 'US', 'zip' => '11111', 'phone' => '+1000000000',
                        ],
                        'items' => [
                            [
                                '__typename' => 'VariantSnapshot', 'product_uid' => 'prod_1', 'id' => 'item_1', '_id' => 1,
                                'title' => 'Widget', 'price' => 20.0, 'sku' => 'W-1', 'fulfillment_status' => 'unfulfilled',
                                'carrier' => null, 'tracking_number' => null, 'tracking_link' => null, 'options' => [],
                            ],
                        ],
                        // Dated relative to now: orders placed before the
                        // store was connected are deliberately skipped by
                        // OrderSyncService, so a hardcoded past date would
                        // make this fixture silently sync zero orders.
                        'notes' => '', 'cancelled_at' => null, 'created_at' => now()->addMinute()->toIso8601String(), 'test' => false,
                    ], 'cursor' => 'oc1'],
                ],
                'pageInfo' => ['endCursor' => 'oc1', 'hasNextPage' => false],
            ]]]),
    ];
}

test('choosing lightfunnels caches the business id and redirects to lightfunnels without creating a store', function () {
    config(['services.lightfunnels.client_id' => 'client-123']);

    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'LightFunnels', 'slug' => 'LightFunnels']);

    $response = $this->actingAs($user)->get(route('stores.connect.redirect', 'LightFunnels'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('app.lightfunnels.com/admin/oauth');
    expect(Store::count())->toBe(0);
});

test('the callback fetches store details and syncs products and orders for the cached business', function () {
    config([
        'services.lightfunnels.client_id' => 'client-123',
        'services.lightfunnels.client_secret' => 'secret-abc',
    ]);

    $user = makeBusinessUser();
    EcommercePlatform::create(['name' => 'LightFunnels', 'slug' => 'LightFunnels']);

    $token = 'connect-token-123';
    Cache::put("lightfunnels_connect.{$token}", $user->business_id, now()->addMinutes(30));

    Http::fake(fakeLightfunnelsProductsAndOrdersResponses());

    $response = $this->actingAs($user)->get(
        route('stores.connect.lightfunnels.callback').'?'.http_build_query(['state' => $token, 'code' => 'auth-code'])
    );

    $store = Store::first();
    $response->assertRedirect(route('stores.connected', $store));
    $response->assertInertiaFlash('toast.type', 'success');

    expect($store->business_id)->toBe($user->business_id);
    expect($store->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
    expect($store->name)->toBe('My LF Store');
    expect($store->external_store_id)->toBe('store_1');
    expect(json_decode($store->api_credentials, true)['access_token'])->toBe('ACCESS-1');
    expect(LightfunnelsService::resolveBusinessId($token))->toBeNull();

    expect(Product::where('store_id', $store->id)->count())->toBe(1);
    expect(Order::where('store_id', $store->id)->count())->toBe(1);

    $order = Order::where('store_id', $store->id)->first();
    expect($order->items()->count())->toBe(1);
    expect($order->items()->first()->quantity)->toBe(1);
});

test('a missing or expired connect token is rejected without creating a store', function () {
    $user = makeBusinessUser();

    $response = $this->actingAs($user)->get(
        route('stores.connect.lightfunnels.callback').'?'.http_build_query(['state' => 'unknown-token', 'code' => 'auth-code'])
    );

    $response->assertRedirect(route('stores.create'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect(Store::count())->toBe(0);
});

test('a network failure during the callback is rejected without creating a store', function () {
    $user = makeBusinessUser();

    $token = 'connect-token-789';
    Cache::put("lightfunnels_connect.{$token}", $user->business_id, now()->addMinutes(30));

    Http::fake(function () {
        throw new ConnectionException('Could not connect to host.');
    });

    $response = $this->actingAs($user)->get(
        route('stores.connect.lightfunnels.callback').'?'.http_build_query(['state' => $token, 'code' => 'auth-code'])
    );

    $response->assertRedirect(route('stores.create'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect(Store::count())->toBe(0);
});
