<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeStoreepPlatform(): EcommercePlatform
{
    return EcommercePlatform::create(['name' => 'Storeep', 'slug' => 'Storeep']);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storeepConnectPayload(array $overrides = []): array
{
    return [
        'name' => 'My Storeep shop',
        'access_token' => '550e8400-e29b-41d4-a716-446655440000',
        ...$overrides,
    ];
}

test('a valid access token creates a connected store', function () {
    $user = makeBusinessUser();
    makeStoreepPlatform();

    Http::fake([
        'api.storeep.com/v1/products*' => Http::response(['data' => [], 'meta' => ['pagination' => ['total_pages' => 1]]], 200),
    ]);

    $response = $this->actingAs($user)->post(
        route('stores.connect.storeep.store'),
        storeepConnectPayload(['market' => 'ma'])
    );

    $store = Store::first();
    $response->assertRedirect(route('stores.connected', $store));
    $response->assertInertiaFlash('toast.type', 'success');

    expect($store->business_id)->toBe($user->business_id);
    expect($store->name)->toBe('My Storeep shop');
    expect($store->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
    expect(json_decode($store->api_credentials, true)['access_token'])
        ->toBe('550e8400-e29b-41d4-a716-446655440000');
    // Lowercase input is normalized, so product pricing lookups match the
    // uppercase market codes Storeep returns.
    expect($store->meta['market'])->toBe('MA');
});

test('the token is proven against the live api before the store is created', function () {
    $user = makeBusinessUser();
    makeStoreepPlatform();

    Http::fake([
        'api.storeep.com/v1/products*' => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($user)->post(route('stores.connect.storeep.store'), storeepConnectPayload());

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.storeep.com/v1/products')
        && $request->hasHeader('Authorization', 'Bearer 550e8400-e29b-41d4-a716-446655440000')
    );
});

test('a rejected access token does not create a store', function () {
    $user = makeBusinessUser();
    makeStoreepPlatform();

    Http::fake([
        'api.storeep.com/*' => Http::response(['status' => 'error', 'errors' => ['access token has expired']], 401),
    ]);

    $response = $this->actingAs($user)->post(
        route('stores.connect.storeep.store'),
        storeepConnectPayload()
    );

    $response->assertSessionHasErrors('access_token');
    expect(Store::count())->toBe(0);
});

test('a token missing the products:read permission is rejected with a permission-specific message', function () {
    $user = makeBusinessUser();
    makeStoreepPlatform();

    Http::fake([
        'api.storeep.com/*' => Http::response(['status' => 'error', 'errors' => ['insufficient permissions']], 403),
    ]);

    $response = $this->actingAs($user)->post(
        route('stores.connect.storeep.store'),
        storeepConnectPayload()
    );

    $response->assertSessionHasErrors('access_token');
    expect(session('errors')->first('access_token'))->toContain('products:read');
    expect(Store::count())->toBe(0);
});

test('a network failure does not create a store', function () {
    $user = makeBusinessUser();
    makeStoreepPlatform();

    Http::fake(function () {
        throw new ConnectionException('Could not connect to host.');
    });

    $response = $this->actingAs($user)->post(
        route('stores.connect.storeep.store'),
        storeepConnectPayload()
    );

    $response->assertSessionHasErrors('access_token');
    expect(Store::count())->toBe(0);
});

test('reconnecting with the same access token refreshes the store instead of duplicating it', function () {
    $user = makeBusinessUser();
    makeStoreepPlatform();

    Http::fake([
        'api.storeep.com/v1/products*' => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($user)->post(route('stores.connect.storeep.store'), storeepConnectPayload());
    $store = Store::first();

    $response = $this->actingAs($user)->post(
        route('stores.connect.storeep.store'),
        storeepConnectPayload(['name' => 'A different name'])
    );

    // Running the connect flow again for a store that already exists is how
    // a merchant repairs a dead connection, so it succeeds onto the same
    // store rather than being rejected as a duplicate.
    $response->assertRedirect(route('stores.connected', $store));
    expect(Store::count())->toBe(1)
        ->and(Store::first()->id)->toBe($store->id)
        // The merchant's existing name is kept — a reconnect refreshes
        // credentials, it does not rename their store.
        ->and(Store::first()->name)->toBe($store->name);
});

test('the access token is never persisted outside the encrypted credentials blob', function () {
    $user = makeBusinessUser();
    makeStoreepPlatform();

    Http::fake(['api.storeep.com/v1/products*' => Http::response(['data' => []], 200)]);

    $this->actingAs($user)->post(route('stores.connect.storeep.store'), storeepConnectPayload());

    // external_store_id has to hold *something* unique per store, and the
    // token is the only stable identity Storeep gives us — so it is stored
    // as a hash, never in the clear.
    $raw = DB::table('stores')->first();
    expect($raw->external_store_id)->toBe(hash('sha256', '550e8400-e29b-41d4-a716-446655440000'));
    expect($raw->api_credentials)->not->toContain('550e8400');
});

test('a name and access token are required', function () {
    $user = makeBusinessUser();
    makeStoreepPlatform();

    $response = $this->actingAs($user)->post(route('stores.connect.storeep.store'), []);

    $response->assertSessionHasErrors(['name', 'access_token']);
    expect(Store::count())->toBe(0);
});

test('a market must be a two-letter code', function () {
    $user = makeBusinessUser();
    makeStoreepPlatform();

    $response = $this->actingAs($user)->post(
        route('stores.connect.storeep.store'),
        storeepConnectPayload(['market' => 'Morocco'])
    );

    $response->assertSessionHasErrors('market');
    expect(Store::count())->toBe(0);
});
