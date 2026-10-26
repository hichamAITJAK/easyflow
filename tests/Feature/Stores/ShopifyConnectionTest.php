<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\ShopifyConnectionRequest;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeShopifyPlatform(): EcommercePlatform
{
    return EcommercePlatform::create(['name' => 'Shopify', 'slug' => 'Shopify']);
}

/** Sign a callback's query params the way Shopify does, with the app secret. */
function shopifyCallbackQuery(array $params, string $secret): string
{
    $sorted = $params;
    ksort($sorted);

    return http_build_query([...$params, 'hmac' => hash_hmac('sha256', http_build_query($sorted), $secret)]);
}

function fakeShopifyApi(): void
{
    Http::fake([
        '*/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_test',
            'scope' => 'read_orders,read_products',
            'refresh_token' => 'shprt_test',
        ]),
        '*graphql.json*' => Http::sequence()
            ->push(['shop' => ['id' => 'gid://shopify/Shop/1', 'name' => 'My Shop', 'myshopifyDomain' => 'my-shop.myshopify.com']])
            ->push(['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'], 'userErrors' => []]])
            ->push(['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/2'], 'userErrors' => []]]),
    ]);
}

test('saving the shop and app keys creates a pending request and shows the finish step, without a store', function () {
    $user = makeBusinessUser();
    makeShopifyPlatform();

    $response = $this->actingAs($user)->post(route('stores.connect.shopify.store'), [
        'shop' => 'https://my-shop.myshopify.com/',
        'client_id' => 'merchant-client-id',
        'client_secret' => 'merchant-secret',
    ]);

    $pending = ShopifyConnectionRequest::first();
    $response->assertRedirect(route('stores.connect.shopify.finish', $pending));

    expect($pending->shop)->toBe('my-shop');
    expect($pending->business_id)->toBe($user->business_id);
    expect($pending->client_id)->toBe('merchant-client-id');
    expect($pending->client_secret)->toBe('merchant-secret');
    expect(Store::count())->toBe(0);

    $this->actingAs($user)->get(route('stores.connect.shopify.finish', $pending))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('stores/shopify-finish')
            ->where('pending.domain', 'my-shop.myshopify.com')
            ->where('redirectUri', route('stores.connect.shopify.callback', absolute: true))
            ->where('authorizeUrl', fn ($url) => str_contains($url, 'my-shop.myshopify.com/admin/oauth/authorize')
                && str_contains($url, 'client_id=merchant-client-id')
                && str_contains($url, 'state='.$pending->state)));
});

test('re-submitting the same shop replaces the keys instead of adding a second request', function () {
    $user = makeBusinessUser();
    makeShopifyPlatform();

    $this->actingAs($user)->post(route('stores.connect.shopify.store'), ['shop' => 'my-shop', 'client_id' => 'old', 'client_secret' => 'old-secret']);
    $this->actingAs($user)->post(route('stores.connect.shopify.store'), ['shop' => 'my-shop.myshopify.com', 'client_id' => 'new', 'client_secret' => 'new-secret']);

    expect(ShopifyConnectionRequest::count())->toBe(1);
    expect(ShopifyConnectionRequest::first()->client_id)->toBe('new');
});

test('an invalid shop domain is rejected', function () {
    $user = makeBusinessUser();
    makeShopifyPlatform();

    $this->actingAs($user)->from(route('stores.create'))->post(route('stores.connect.shopify.store'), [
        'shop' => 'not a domain!',
        'client_id' => 'x',
        'client_secret' => 'y',
    ])->assertSessionHasErrors('shop');

    expect(ShopifyConnectionRequest::count())->toBe(0);
});

test('the callback with our state connects the store using the merchant keys and keeps them with the token', function () {
    config(['services.shopify.client_id' => 'platform-id', 'services.shopify.client_secret' => 'platform-secret']);

    $user = makeBusinessUser();
    makeShopifyPlatform();
    $pending = ShopifyConnectionRequest::create([
        'business_id' => $user->business_id,
        'shop' => 'my-shop',
        'client_id' => 'merchant-client-id',
        'client_secret' => 'merchant-secret',
        'state' => 'state-123',
    ]);
    fakeShopifyApi();

    $response = $this->actingAs($user)->get(
        route('stores.connect.shopify.callback').'?'.shopifyCallbackQuery(
            ['shop' => 'my-shop.myshopify.com', 'code' => 'auth-code', 'state' => 'state-123'],
            'merchant-secret',
        )
    );

    $store = Store::first();
    $response->assertRedirect(route('stores.connected', $store));
    $response->assertInertiaFlash('toast.type', 'success');

    expect($store->business_id)->toBe($user->business_id);
    expect($store->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
    expect($store->external_store_id)->toBe('my-shop');

    $credentials = json_decode($store->api_credentials, true);
    expect($credentials['access_token'])->toBe('shpat_test');
    expect($credentials['client_id'])->toBe('merchant-client-id');
    expect($credentials['client_secret'])->toBe('merchant-secret');

    // The token exchange went to Shopify with the merchant's keys.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/oauth/access_token')
        && $request['client_id'] === 'merchant-client-id'
        && $request['client_secret'] === 'merchant-secret');

    expect(ShopifyConnectionRequest::find($pending->id))->toBeNull();
});

test('the callback from the install link, with no state, finds the request by shop', function () {
    $user = makeBusinessUser();
    makeShopifyPlatform();
    ShopifyConnectionRequest::create([
        'business_id' => $user->business_id,
        'shop' => 'my-shop',
        'client_id' => 'merchant-client-id',
        'client_secret' => 'merchant-secret',
        'state' => 'state-456',
    ]);
    fakeShopifyApi();

    $response = $this->actingAs($user)->get(
        route('stores.connect.shopify.callback').'?'.shopifyCallbackQuery(
            ['shop' => 'my-shop.myshopify.com', 'code' => 'auth-code', 'timestamp' => '1700000000'],
            'merchant-secret',
        )
    );

    $response->assertRedirect(route('stores.connected', Store::first()));
    expect(Store::count())->toBe(1);
});

test('a callback with no pending request is rejected without creating a store', function () {
    $user = makeBusinessUser();
    makeShopifyPlatform();

    $response = $this->actingAs($user)->get(
        route('stores.connect.shopify.callback').'?'.shopifyCallbackQuery(
            ['shop' => 'my-shop.myshopify.com', 'code' => 'auth-code', 'state' => 'unknown'],
            'whatever',
        )
    );

    $response->assertRedirect(route('stores.create'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect(Store::count())->toBe(0);
});

test('a callback signed with the wrong secret is rejected and sent back to the finish step', function () {
    $user = makeBusinessUser();
    makeShopifyPlatform();
    $pending = ShopifyConnectionRequest::create([
        'business_id' => $user->business_id,
        'shop' => 'my-shop',
        'client_id' => 'merchant-client-id',
        'client_secret' => 'merchant-secret',
        'state' => 'state-789',
    ]);

    $response = $this->actingAs($user)->get(
        route('stores.connect.shopify.callback').'?'.shopifyCallbackQuery(
            ['shop' => 'my-shop.myshopify.com', 'code' => 'auth-code', 'state' => 'state-789'],
            'a-different-secret',
        )
    );

    $response->assertRedirect(route('stores.connect.shopify.finish', $pending));
    $response->assertInertiaFlash('toast.type', 'error');
    expect(Store::count())->toBe(0);
    expect(ShopifyConnectionRequest::find($pending->id))->not->toBeNull();
});

test('another business cannot finish or use someone else\'s pending request', function () {
    $owner = makeBusinessUser();
    $stranger = makeBusinessUser();
    makeShopifyPlatform();
    $pending = ShopifyConnectionRequest::create([
        'business_id' => $owner->business_id,
        'shop' => 'my-shop',
        'client_id' => 'merchant-client-id',
        'client_secret' => 'merchant-secret',
        'state' => 'state-000',
    ]);

    $this->actingAs($stranger)->get(route('stores.connect.shopify.finish', $pending))->assertForbidden();

    $this->actingAs($stranger)->get(
        route('stores.connect.shopify.callback').'?'.shopifyCallbackQuery(
            ['shop' => 'my-shop.myshopify.com', 'code' => 'auth-code', 'state' => 'state-000'],
            'merchant-secret',
        )
    )->assertForbidden();

    expect(Store::count())->toBe(0);
});

test('the old redirect entry point no longer handles Shopify', function () {
    $user = makeBusinessUser();
    makeShopifyPlatform();

    $this->actingAs($user)->get(route('stores.connect.redirect', 'Shopify').'?shop=my-shop')->assertNotFound();
});

test('an order webhook is verified with the store\'s own secret', function () {
    Queue::fake();
    config(['services.shopify.client_secret' => 'platform-secret']);

    $user = makeBusinessUser();
    $platform = makeShopifyPlatform();
    $store = Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'My Shop',
        'external_store_id' => 'my-shop',
        'api_credentials' => json_encode(['access_token' => 'shpat_test', 'client_id' => 'merchant-client-id', 'client_secret' => 'merchant-secret']),
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    $payload = json_encode(['id' => 42]);
    $post = fn (string $secret) => $this->call('POST', route('webhooks.shopify.receive', $store), [], [], [],
        ['HTTP_X_Shopify_Hmac_Sha256' => base64_encode(hash_hmac('sha256', $payload, $secret, true)), 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $post('merchant-secret')->assertOk();
    $post('platform-secret')->assertStatus(401);
});
