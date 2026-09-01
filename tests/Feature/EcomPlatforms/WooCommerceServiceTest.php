<?php

use App\DTOs\WooCommerce\WooCommerceOrderDTO;
use App\DTOs\WooCommerce\WooCommerceProductDTO;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Operations\EcomPlatforms\WooCommerceService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const WOO_SVC_HOST = 'shop.example.com';

function makeWooStore(): Store
{
    $admin = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'WooCommerce', 'slug' => 'WooCommerce']);

    return Store::create([
        'business_id' => $admin->business_id,
        'platform_id' => $platform->id,
        'external_store_id' => 'https://'.WOO_SVC_HOST,
        'name' => 'Example shop',
        'slug' => 'example-shop',
        'connection_status' => 'connected',
        'api_credentials' => json_encode([
            'store_url' => 'https://'.WOO_SVC_HOST,
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]),
    ]);
}

/**
 * A faked list response carrying WooCommerce's pagination header, which is
 * where WordPress reports the page count rather than in the body.
 *
 * @param  array<int, array<string, mixed>>  $items
 */
function wooPage(array $items, int $totalPages = 1): PromiseInterface
{
    return Http::response($items, 200, ['X-WP-TotalPages' => (string) $totalPages]);
}

// ----------------------------------------------------------------
// Orders
// ----------------------------------------------------------------

test('loadOrders maps a woocommerce order into the shared shape', function () {
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/orders*' => wooPage([[
            'id' => 727,
            'number' => '1042',
            'status' => 'processing',
            'currency' => 'MAD',
            'total' => '529.00',
            'shipping_total' => '30.00',
            'discount_total' => '0.00',
            'total_tax' => '0.00',
            'payment_method' => 'cod',
            'payment_method_title' => 'Cash on delivery',
            'customer_note' => 'Call before delivery.',
            'date_created_gmt' => '2026-08-01T10:00:00',
            'billing' => [
                'first_name' => 'Youssef',
                'last_name' => 'Alami',
                'address_1' => '12 Rue Ibn Batouta',
                'city' => 'Casablanca',
                'country' => 'MA',
                'email' => 'youssef@example.com',
                'phone' => '0600000000',
            ],
            'shipping' => [
                'first_name' => 'Youssef',
                'last_name' => 'Alami',
                'address_1' => '12 Rue Ibn Batouta',
                'city' => 'Casablanca',
                'country' => 'MA',
            ],
            'line_items' => [[
                'id' => 1,
                'product_id' => 93,
                'variation_id' => 0,
                'name' => 'T-shirt',
                'sku' => 'TS-1',
                'quantity' => 2,
                'price' => 249.5,
                'total' => '499.00',
            ]],
        ]]),
    ]);

    $orders = (new WooCommerceService(makeWooStore()))->loadOrders();

    expect($orders)->toHaveCount(1);

    $order = $orders[0]->toArray();
    expect($order['id'])->toBe('727');
    expect($order['reference'])->toBe('1042');
    expect($order['total'])->toBe(529.0);
    expect($order['delivery_cost'])->toBe(30.0);
    expect($order['customer_name'])->toBe('Youssef Alami');
    expect($order['customer_phone'])->toBe('0600000000');
    expect($order['shipping_address']['city'])->toBe('Casablanca');
    expect($order['line_items'][0]['quantity'])->toBe(2);
    // A simple product's line item reports variation_id 0 — that is "no
    // variant", not variant zero.
    expect($order['line_items'][0]['variant_id'])->toBeNull();
});

test('the delivery address falls back to billing when shipping is blank', function () {
    // The common COD case: no separate shipping step, so WooCommerce returns
    // a `shipping` object that is present but entirely empty. Reading it
    // alone would produce an order with no address and no phone.
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/orders*' => wooPage([[
            'id' => 1,
            'total' => '100.00',
            'billing' => [
                'first_name' => 'Sara',
                'last_name' => 'Bennani',
                'address_1' => '8 Avenue Hassan II',
                'city' => 'Rabat',
                'phone' => '0611111111',
                'email' => 'sara@example.com',
            ],
            'shipping' => [
                'first_name' => '',
                'last_name' => '',
                'address_1' => '',
                'city' => '',
                'postcode' => '',
            ],
            'line_items' => [],
        ]]),
    ]);

    $order = (new WooCommerceService(makeWooStore()))->loadOrders()[0]->toArray();

    expect($order['customer_name'])->toBe('Sara Bennani');
    expect($order['customer_phone'])->toBe('0611111111');
    expect($order['shipping_address']['city'])->toBe('Rabat');
    expect($order['shipping_address']['first_line'])->toBe('8 Avenue Hassan II');
});

test('the phone falls back to billing when shipping carries none', function () {
    // shipping.phone only exists on WooCommerce 5.6+, and is often blank
    // even there. The phone is the field a COD call-center runs on.
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/orders*' => wooPage([[
            'id' => 1,
            'total' => '100.00',
            'billing' => ['first_name' => 'Sara', 'phone' => '0611111111'],
            'shipping' => ['first_name' => 'Sara', 'address_1' => '8 Avenue Hassan II', 'city' => 'Rabat'],
            'line_items' => [],
        ]]),
    ]);

    $order = (new WooCommerceService(makeWooStore()))->loadOrders()[0]->toArray();

    expect($order['customer_phone'])->toBe('0611111111');
    // The address itself still comes from shipping, which is populated.
    expect($order['shipping_address']['city'])->toBe('Rabat');
});

test('loadOrders scopes to the connect date in GMT', function () {
    Http::fake([WOO_SVC_HOST.'/wp-json/wc/v3/orders*' => wooPage([])]);

    (new WooCommerceService(makeWooStore()))->loadOrders(now()->subDays(7));

    Http::assertSent(function ($request) {
        // dates_are_gmt is what makes the boundary unambiguous — without it
        // WooCommerce reads the date in store-local time and silently shifts
        // the cutoff by the store's UTC offset.
        return str_contains($request->url(), 'dates_are_gmt=true')
            && str_contains($request->url(), 'after=')
            && str_contains($request->url(), 'status=any');
    });
});

test('every page of orders is walked using the total-pages header', function () {
    // Walking to X-WP-TotalPages (rather than until a short page arrives) is
    // what makes this correct: a page can legitimately come back short.
    Http::fakeSequence()
        ->push([['id' => 1, 'total' => '10.00', 'line_items' => []]], 200, ['X-WP-TotalPages' => '2'])
        ->push([['id' => 2, 'total' => '20.00', 'line_items' => []]], 200, ['X-WP-TotalPages' => '2']);

    $orders = (new WooCommerceService(makeWooStore()))->loadOrders();

    expect($orders)->toHaveCount(2);
});

test('a subtotal is derived when woocommerce reports none', function () {
    // WooCommerce has no order-level subtotal field; goods =
    // total - shipping - tax + discount.
    $order = WooCommerceOrderDTO::fromArray([
        'id' => 1,
        'total' => '529.00',
        'shipping_total' => '30.00',
        'total_tax' => '0.00',
        'discount_total' => '0.00',
        'line_items' => [],
    ]);

    expect($order->subtotal)->toBe(499.0);
});

test('a cod order is not marked paid', function () {
    // date_paid stays null until cash is collected.
    $order = WooCommerceOrderDTO::fromArray([
        'id' => 1, 'total' => '100.00', 'date_paid' => null, 'line_items' => [],
    ]);

    expect($order->isPaid)->toBeFalse();
});

test('a line item variant title is built from its meta data', function () {
    // WooCommerce puts the chosen options in meta_data, not in a variant
    // title field. Internal keys (underscore-prefixed) must be skipped.
    $order = WooCommerceOrderDTO::fromArray([
        'id' => 1,
        'total' => '100.00',
        'line_items' => [[
            'id' => 1,
            'product_id' => 5,
            'variation_id' => 9,
            'name' => 'T-shirt',
            'quantity' => 1,
            'meta_data' => [
                ['display_key' => 'Size', 'display_value' => 'M'],
                ['display_key' => 'Color', 'display_value' => 'Red'],
                ['key' => '_internal_plugin_data', 'value' => 'ignore me'],
            ],
        ]],
    ]);

    expect($order->lineItems[0]->variantTitle)->toBe('Size: M, Color: Red');
    expect($order->lineItems[0]->variantId)->toBe('9');
});

// ----------------------------------------------------------------
// Products
// ----------------------------------------------------------------

test('a simple product synthesizes a single variant from itself', function () {
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/products?*' => wooPage([[
            'id' => 93,
            'name' => 'T-shirt',
            'type' => 'simple',
            'status' => 'publish',
            'sku' => 'TS-1',
            'price' => '199.00',
            'regular_price' => '249.00',
            'sale_price' => '199.00',
            'stock_quantity' => 12,
            'stock_status' => 'instock',
            'permalink' => 'https://'.WOO_SVC_HOST.'/product/t-shirt',
            'images' => [['src' => 'https://'.WOO_SVC_HOST.'/tshirt.jpg']],
        ]]),
    ]);

    $products = (new WooCommerceService(makeWooStore()))->loadProducts();

    expect($products)->toHaveCount(1);

    $product = $products[0]->toArray();
    expect($product['title'])->toBe('T-shirt');
    expect($product['status'])->toBe('active');
    expect($product['variants'])->toHaveCount(1);
    expect($product['variants'][0]['sku'])->toBe('TS-1');
    expect($product['variants'][0]['price'])->toBe(199.0);
    expect($product['variants'][0]['inventory_quantity'])->toBe(12);
});

test('a variable product loads its rows from the variations endpoint', function () {
    // The parent of a variable product carries only variation IDs — the real
    // SKUs, prices and stock live one endpoint deeper.
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/products/93/variations*' => wooPage([
            [
                'id' => 100,
                'sku' => 'TS-1-M',
                'price' => '199.00',
                'stock_quantity' => 4,
                'stock_status' => 'instock',
                'attributes' => [['name' => 'Size', 'option' => 'M']],
            ],
            [
                'id' => 101,
                'sku' => 'TS-1-L',
                'price' => '209.00',
                'stock_quantity' => 0,
                'stock_status' => 'outofstock',
                'attributes' => [['name' => 'Size', 'option' => 'L']],
            ],
        ]),
        WOO_SVC_HOST.'/wp-json/wc/v3/products?*' => wooPage([[
            'id' => 93,
            'name' => 'T-shirt',
            'type' => 'variable',
            'status' => 'publish',
            'attributes' => [['name' => 'Size', 'options' => ['M', 'L'], 'variation' => true]],
        ]]),
    ]);

    $product = (new WooCommerceService(makeWooStore()))->loadProducts()[0]->toArray();

    expect($product['variants'])->toHaveCount(2);
    expect($product['variants'][0]['sku'])->toBe('TS-1-M');
    expect($product['variants'][0]['title'])->toBe('M');
    expect($product['variants'][1]['available'])->toBeFalse();
    expect($product['option_axes'][0]['values'])->toBe(['M', 'L']);
});

test('untracked stock is null rather than zero', function () {
    // With manage_stock off WooCommerce reports null, meaning
    // untracked/unlimited — not zero, which reads as out of stock.
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/products?*' => wooPage([[
            'id' => 1,
            'name' => 'Poster',
            'type' => 'simple',
            'status' => 'publish',
            'manage_stock' => false,
            'stock_quantity' => null,
            'stock_status' => 'instock',
        ]]),
    ]);

    $product = (new WooCommerceService(makeWooStore()))->loadProducts()[0];

    expect($product->variants[0]->inventoryQuantity)->toBeNull();
    expect($product->variants[0]->available)->toBeTrue();
});

test('a draft product is mapped to inactive', function () {
    // WooCommerce statuses are WordPress post statuses; only `publish` is
    // live. ProductSyncService checks against 'active'/'inactive'.
    $product = WooCommerceProductDTO::fromArray([
        'id' => 1, 'name' => 'Hidden', 'type' => 'simple', 'status' => 'draft',
    ]);

    expect($product->status)->toBe('inactive');
});

test('non-variation attributes are not exposed as option axes', function () {
    // "Material: Cotton" is a spec/filter attribute, not an axis the
    // customer picks from.
    $product = WooCommerceProductDTO::fromArray([
        'id' => 1,
        'name' => 'T-shirt',
        'type' => 'variable',
        'attributes' => [
            ['name' => 'Size', 'options' => ['M'], 'variation' => true],
            ['name' => 'Material', 'options' => ['Cotton'], 'variation' => false],
        ],
    ]);

    expect($product->optionAxes)->toHaveCount(1);
    expect($product->optionAxes[0]['name'])->toBe('Size');
});

test('a failure reading one product variations does not fail the catalog', function () {
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/products/93/variations*' => Http::response(['message' => 'boom'], 500),
        WOO_SVC_HOST.'/wp-json/wc/v3/products?*' => wooPage([[
            'id' => 93, 'name' => 'T-shirt', 'type' => 'variable', 'status' => 'publish',
        ]]),
    ]);

    $products = (new WooCommerceService(makeWooStore()))->loadProducts();

    // The product still syncs, with the row synthesized from the parent.
    expect($products)->toHaveCount(1);
    expect($products[0]->variants)->toHaveCount(1);
});

// ----------------------------------------------------------------
// Webhooks
// ----------------------------------------------------------------

test('registering subscribes to both order topics with an explicit secret', function () {
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/webhooks' => Http::response(['id' => 12], 201),
        WOO_SVC_HOST.'/wp-json/wc/v3/webhooks?*' => wooPage([]),
    ]);

    $store = makeWooStore();
    (new WooCommerceService($store))->registerOrderWebhook();

    $topics = [];

    Http::assertSent(function ($request) use (&$topics) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/webhooks')) {
            $topics[] = $request->data()['topic'];

            // Omitting the secret would make WooCommerce sign with the
            // consumer key instead, so verification would depend on which
            // key created the webhook.
            expect($request->data()['secret'])->not->toBeEmpty();
        }

        return true;
    });

    expect($topics)->toContain('order.created');
    expect($topics)->toContain('order.updated');
    expect($store->fresh()->webhook_secret)->not->toBeEmpty();
});

test('deregistering deletes only our own webhooks', function () {
    // A merchant may well have their own order webhooks pointing elsewhere;
    // deleting those would silently break their integration.
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/webhooks/*' => Http::response([], 200),
        WOO_SVC_HOST.'/wp-json/wc/v3/webhooks*' => wooPage([
            ['id' => 1, 'delivery_url' => 'https://easyflow.test/webhooks/woocommerce/5/create-order'],
            ['id' => 2, 'delivery_url' => 'https://merchants-own-crm.example.com/hook'],
        ]),
    ]);

    (new WooCommerceService(makeWooStore()))->deregisterAllOrderWebhooks();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/webhooks/1'));

    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/webhooks/2'));
});

test('a failed webhook subscription does not fail the connection', function () {
    // UC-4: a store must stay usable via the nightly reconciliation sync
    // even when its webhook subscription fails.
    Http::fake([
        WOO_SVC_HOST.'/wp-json/wc/v3/webhooks' => Http::response(['message' => 'boom'], 500),
        WOO_SVC_HOST.'/wp-json/wc/v3/webhooks?*' => wooPage([]),
    ]);

    $store = makeWooStore();

    (new WooCommerceService($store))->registerOrderWebhook();

    // The secret is still persisted, so a later manual retry can reuse it.
    expect($store->fresh()->webhook_secret)->not->toBeEmpty();
});

test('getStoreDetails falls back to stored values when the store is unreachable', function () {
    Http::fake([WOO_SVC_HOST.'/wp-json/wc/v3/system_status' => Http::response([], 500)]);

    $store = makeWooStore();
    $details = (new WooCommerceService($store))->getStoreDetails();

    expect($details['name'])->toBe('Example shop');
});
