<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Operations\EcomPlatforms\YouCanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeYouCanPlatform(): EcommercePlatform
{
    return EcommercePlatform::create(['name' => 'YouCan', 'slug' => 'YouCan']);
}

test('redirect caches the business id keyed by session and redirects to youcan without creating a store', function () {
    config(['services.youcan.client_id' => 'client-123']);

    $user = makeBusinessUser();
    makeYouCanPlatform();

    $response = $this->actingAs($user)->get(route('stores.connect.redirect', 'YouCan'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('seller-area.youcan.shop/admin/oauth/authorize');
    expect(Store::count())->toBe(0);
});

test('the callback creates a connected store for the session-cached business', function () {
    config(['services.youcan.client_id' => 'client-123']);

    $user = makeBusinessUser();
    makeYouCanPlatform();

    // Sequential test requests each get a fresh session id (no real cookie
    // jar round-trip), so the session id is pinned via the session cookie
    // and the cache is seeded under it directly — mirroring what
    // YouCanService::connect() does for a real browser request.
    $sessionId = Str::random(40);
    Cache::put('youcan_connect.'.$sessionId, $user->business_id, now()->addMinutes(5));

    Http::fake([
        '*/oauth/token' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 1000,
            'access_token' => 'ACCESS-1',
            'refresh_token' => 'REFRESH-1',
        ], 200),
        '*/resthooks/list' => Http::response(['data' => []], 200),
        '*/resthooks/subscribe' => Http::response(['id' => 'hook-1'], 200),
    ]);

    $response = $this->actingAs($user)
        ->withCookie(config('session.cookie'), $sessionId)
        ->get(route('stores.connect.youcan.callback').'?'.http_build_query(['code' => 'auth-code']));

    $store = Store::first();
    $response->assertRedirect(route('stores.connected', $store));
    $response->assertInertiaFlash('toast.type', 'success');

    expect($store->business_id)->toBe($user->business_id);
    expect($store->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
    expect(json_decode($store->api_credentials, true)['access_token'])->toBe('ACCESS-1');
    expect(YouCanService::resolveBusinessId())->toBeNull();
});

test('a missing or expired connect session is rejected without creating a store', function () {
    $user = makeBusinessUser();
    makeYouCanPlatform();

    $response = $this->actingAs($user)->get(
        route('stores.connect.youcan.callback').'?'.http_build_query(['code' => 'auth-code'])
    );

    $response->assertRedirect(route('stores.create'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect(Store::count())->toBe(0);
});

test('a rejected authorization code does not create a store', function () {
    config(['services.youcan.client_id' => 'client-123']);

    $user = makeBusinessUser();
    makeYouCanPlatform();

    $this->actingAs($user)->get(route('stores.connect.redirect', 'YouCan'));

    Http::fake(['youcan.shop/*' => Http::response(['message' => 'invalid_grant'], 400)]);

    $response = $this->get(
        route('stores.connect.youcan.callback').'?'.http_build_query(['code' => 'bad-code'])
    );

    $response->assertRedirect(route('stores.create'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect(Store::count())->toBe(0);
});

test('a network failure during the callback is rejected without creating a store', function () {
    config(['services.youcan.client_id' => 'client-123']);

    $user = makeBusinessUser();
    makeYouCanPlatform();

    $this->actingAs($user)->get(route('stores.connect.redirect', 'YouCan'));

    Http::fake(function () {
        throw new ConnectionException('Could not connect to host.');
    });

    $response = $this->get(
        route('stores.connect.youcan.callback').'?'.http_build_query(['code' => 'auth-code'])
    );

    $response->assertRedirect(route('stores.create'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect(Store::count())->toBe(0);
});
