<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Operations\EcomPlatforms\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeShopifyPlatform(): EcommercePlatform
{
    return EcommercePlatform::create(['name' => 'Shopify', 'slug' => 'Shopify']);
}

test('redirect caches the business id and shop then redirects to shopify without creating a store', function () {
    config(['services.shopify.client_id' => 'client-123']);

    $user = makeBusinessUser();
    makeShopifyPlatform();

    $response = $this->actingAs($user)->get(
        route('stores.connect.redirect', 'Shopify').'?shop=my-shop'
    );

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('my-shop.myshopify.com/admin/oauth/authorize')
        ->toContain('client_id=client-123');

    expect(Store::count())->toBe(0);
});

test('an invalid shop domain is rejected before redirecting', function () {
    $user = makeBusinessUser();
    makeShopifyPlatform();

    $response = $this->actingAs($user)->get(
        route('stores.connect.redirect', 'Shopify').'?shop='.urlencode('not a domain!')
    );

    $response->assertSessionHasErrors('shop');
    expect(Store::count())->toBe(0);
});

test('the callback creates a connected store for the cached business on a valid hmac', function () {
    config(['services.shopify.client_id' => 'client-123', 'services.shopify.client_secret' => 'secret-abc']);

    $user = makeBusinessUser();
    makeShopifyPlatform();

    $token = 'connect-token-123';
    Cache::put("shopify_connect.{$token}", ['business_id' => $user->business_id, 'shop' => 'my-shop'], now()->addMinutes(30));

    Http::fake([
        '*/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_test',
            'scope' => 'read_orders,read_products',
        ]),
        '*graphql.json*' => Http::sequence()
            ->push(['shop' => ['id' => 'gid://shopify/Shop/1', 'name' => 'My Shop', 'myshopifyDomain' => 'my-shop.myshopify.com']])
            ->push(['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'], 'userErrors' => []]]),
    ]);

    $params = ['shop' => 'my-shop.myshopify.com', 'code' => 'auth-code', 'state' => $token];
    $sorted = $params;
    ksort($sorted);
    $hmac = hash_hmac('sha256', http_build_query($sorted), 'secret-abc');

    $response = $this->actingAs($user)->get(
        route('stores.connect.shopify.callback').'?'.http_build_query([...$params, 'hmac' => $hmac])
    );

    $store = Store::first();
    $response->assertRedirect(route('stores.connected', $store));
    $response->assertInertiaFlash('toast.type', 'success');

    expect($store->business_id)->toBe($user->business_id);
    expect($store->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
    expect($store->external_store_id)->toBe('my-shop');
    expect(ShopifyService::resolveConnection($token))->toBeNull();
});

test('a missing or expired connect token is rejected without creating a store', function () {
    config(['services.shopify.client_secret' => 'secret-abc']);

    $user = makeBusinessUser();
    makeShopifyPlatform();

    $params = ['shop' => 'my-shop.myshopify.com', 'code' => 'auth-code', 'state' => 'unknown-token'];
    $sorted = $params;
    ksort($sorted);
    $hmac = hash_hmac('sha256', http_build_query($sorted), 'secret-abc');

    $response = $this->actingAs($user)->get(
        route('stores.connect.shopify.callback').'?'.http_build_query([...$params, 'hmac' => $hmac])
    );

    $response->assertRedirect(route('stores.create'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect(Store::count())->toBe(0);
});

test('the callback rejects an invalid hmac without creating a store', function () {
    config(['services.shopify.client_secret' => 'secret-abc']);

    $user = makeBusinessUser();
    makeShopifyPlatform();

    $token = 'connect-token-456';
    Cache::put("shopify_connect.{$token}", ['business_id' => $user->business_id, 'shop' => 'my-shop'], now()->addMinutes(30));

    $response = $this->actingAs($user)->get(
        route('stores.connect.shopify.callback').'?'.http_build_query([
            'shop' => 'my-shop.myshopify.com',
            'code' => 'auth-code',
            'state' => $token,
            'hmac' => 'not-a-valid-hmac',
        ])
    );

    $response->assertRedirect(route('stores.create'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect(Store::count())->toBe(0);
});
