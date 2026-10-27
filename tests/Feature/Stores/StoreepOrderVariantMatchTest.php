<?php

use App\DTOs\Storeep\StoreepOrderDTO;
use App\DTOs\Storeep\StoreepProductDTO;
use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\OrderItem;
use App\Models\Store;
use App\Services\Operations\Orders\OrderSyncService;
use App\Services\Operations\Products\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a storeep order line with no sku links to the product and the variant by name and colour', function () {
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'Storeep', 'slug' => 'Storeep']);
    $store = Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'My Storeep shop',
        'external_store_id' => 'tok',
        'api_credentials' => json_encode(['access_token' => 'tok']),
        'connection_status' => StoreConnectionStatus::CONNECTED,
        'meta' => ['market' => 'MA'],
    ]);

    // The product as GET /products returns it: one colour axis, three rows.
    $product = StoreepProductDTO::fromArray([
        'id' => 373353392703019593,
        'name' => 'markaz al ihssan',
        'media' => [],
        'variants' => [['name' => 'color', 'type' => 'color', 'options' => [
            ['color' => '#a50d0d', 'value' => 'red'], ['color' => '#ffffff', 'value' => 'white'], ['color' => '#0da536', 'value' => 'green'],
        ]]],
        'is_published' => true,
        'options' => [
            ['id' => 0, 'sku' => null, 'markets' => [['market' => 'MA', 'currency' => 'MAD', 'price' => 399, 'discounted_price' => 299]]],
            ['id' => 1, 'sku' => null, 'markets' => [['market' => 'MA', 'currency' => 'MAD', 'price' => 399, 'discounted_price' => 199]]],
            ['id' => 2, 'sku' => null, 'markets' => [['market' => 'MA', 'currency' => 'MAD', 'price' => 199, 'discounted_price' => null]]],
        ],
    ], 'MA');

    $productSync = app(ProductSyncService::class);
    (new ReflectionMethod($productSync, 'upsertProduct'))->invoke($productSync, $store, $product->toArray());

    // The order as GET /orders returns it: no product id, no sku, flat axis.
    $order = StoreepOrderDTO::fromArray([
        'id' => 373372475985106026,
        'number' => 1,
        'total' => 299,
        'currency' => 'MAD',
        'market' => 'MA',
        'created_at' => now()->toDateTimeString(),
        'items' => [[
            'name' => 'markaz al ihssan',
            'price' => 299,
            'quantity' => 1,
            'sku' => null,
            'variants' => [['name' => 'color', 'type' => 'color', 'color' => '#a50d0d', 'value' => 'red']],
        ]],
        'addresses' => [['type' => 'shipping', 'fullname' => 'Hicham AITJAK', 'city' => 'Casablanca', 'phone' => '+212646437398', 'address1' => 'Block 8']],
    ]);

    app(OrderSyncService::class)->syncOne($store, $order->toArray());

    $item = OrderItem::first();
    expect($item->product_id)->not->toBeNull();
    expect($item->product->name)->toBe('markaz al ihssan');
    expect($item->product_variant_id)->not->toBeNull();
    expect($item->variant->optionValues->first()->value)->toBe('red');
    expect((float) $item->variant->price)->toBe(299.0);
    expect($item->product_name_snapshot)->toBe('markaz al ihssan (red)');
});
