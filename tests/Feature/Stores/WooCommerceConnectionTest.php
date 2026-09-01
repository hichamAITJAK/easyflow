<?php

use App\Enums\StoreConnectionStatus;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Connectivity\WooCommerce\WooCommerceStoreUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeWooCommercePlatform(): EcommercePlatform
{
    return EcommercePlatform::create(['name' => 'WooCommerce', 'slug' => 'WooCommerce']);
}

/**
 * A host that genuinely resolves in public DNS.
 *
 * The connect flow vets the merchant-supplied URL against real DNS (see
 * WooCommerceStoreUrl), so a made-up domain would be rejected before the
 * faked HTTP layer is ever reached — the test would then pass for entirely
 * the wrong reason. example.com is reserved by RFC 2606 and resolves to a
 * public address, so it exercises the real code path while the Http fake
 * ensures nothing is actually sent to it.
 */
const WOO_HOST = 'example.com';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wooConnectPayload(array $overrides = []): array
{
    return [
        'name' => 'My WooCommerce shop',
        'store_url' => 'https://'.WOO_HOST,
        'consumer_key' => 'ck_test_key',
        'consumer_secret' => 'cs_test_secret',
        ...$overrides,
    ];
}

function fakeWooSystemStatus(): void
{
    Http::fake([
        WOO_HOST.'/wp-json/wc/v3/system_status' => Http::response([
            'environment' => [
                'site_url' => 'https://'.WOO_HOST,
                'site_title' => 'Example shop',
                'version' => '9.4.2',
                'wp_version' => '6.7',
                'currency' => 'MAD',
            ],
        ], 200),
    ]);
}

test('valid credentials create a connected store', function () {
    $user = makeBusinessUser();
    makeWooCommercePlatform();
    fakeWooSystemStatus();

    $response = $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload());

    $store = Store::first();
    $response->assertRedirect(route('stores.connected', $store));
    $response->assertInertiaFlash('toast.type', 'success');

    expect($store->business_id)->toBe($user->business_id);
    expect($store->name)->toBe('My WooCommerce shop');
    expect($store->connection_status)->toBe(StoreConnectionStatus::CONNECTED);

    $credentials = json_decode($store->api_credentials, true);
    expect($credentials['consumer_key'])->toBe('ck_test_key');
    expect($credentials['consumer_secret'])->toBe('cs_test_secret');
    expect($credentials['store_url'])->toBe('https://'.WOO_HOST);
});

test('the credentials are proven against the live store before it is created', function () {
    $user = makeBusinessUser();
    makeWooCommercePlatform();

    Http::fake([
        WOO_HOST.'/wp-json/wc/v3/system_status' => Http::response(['message' => 'Invalid signature'], 401),
    ]);

    $response = $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload());

    $response->assertSessionHasErrors('consumer_key');
    expect(Store::count())->toBe(0);
});

test('a 404 blames the store url rather than the keys', function () {
    // No WooCommerce REST API at that address — the merchant has to fix the
    // URL, not the key pair, so the error must land on that field.
    $user = makeBusinessUser();
    makeWooCommercePlatform();

    Http::fake([
        WOO_HOST.'/wp-json/wc/v3/system_status' => Http::response(['message' => 'No route was found'], 404),
    ]);

    $response = $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload());

    $response->assertSessionHasErrors('store_url');
    $response->assertSessionDoesntHaveErrors('consumer_key');
    expect(Store::count())->toBe(0);
});

test('a read-only key rejection points at the permission setting', function () {
    $user = makeBusinessUser();
    makeWooCommercePlatform();

    Http::fake([
        WOO_HOST.'/wp-json/wc/v3/system_status' => Http::response(['code' => 'woocommerce_rest_cannot_view'], 403),
    ]);

    $response = $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload());

    $response->assertSessionHasErrors('consumer_key');
    expect(Store::count())->toBe(0);
});

test('an unreachable store is reported as a url problem', function () {
    $user = makeBusinessUser();
    makeWooCommercePlatform();

    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $response = $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload());

    $response->assertSessionHasErrors('store_url');
    expect(Store::count())->toBe(0);
});

test('connecting the same site again re-keys the existing store rather than duplicating it', function () {
    $user = makeBusinessUser();
    makeWooCommercePlatform();
    fakeWooSystemStatus();

    $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload());
    $store = Store::first();

    $response = $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload([
        'name' => 'Same shop again',
        // The site's origin is the store's identity, not the credentials —
        // so a fresh key pair for the same site is a *reconnect*. Rotated
        // WooCommerce keys are the most common reason a merchant needs one.
        'consumer_key' => 'ck_another_key',
        'consumer_secret' => 'cs_another_secret',
    ]));

    $response->assertRedirect(route('stores.connected', $store));

    expect(Store::count())->toBe(1)
        ->and(Store::first()->id)->toBe($store->id)
        ->and(Store::first()->name)->toBe($store->name);

    // The new key pair replaced the old one, which is the whole point of
    // running the flow a second time.
    expect(json_decode(Store::first()->api_credentials, true)['consumer_key'])
        ->toBe('ck_another_key');
});

test('the store url is normalized to a bare https origin', function () {
    $user = makeBusinessUser();
    makeWooCommercePlatform();
    fakeWooSystemStatus();

    // Merchants paste all of these; every one means the same store.
    $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload([
        'store_url' => '  https://'.WOO_HOST.'/wp-json/wc/v3/  ',
    ]));

    expect(Store::first()->external_store_id)->toBe('https://'.WOO_HOST);
});

test('a bare domain is accepted and assumed to be https', function () {
    $user = makeBusinessUser();
    makeWooCommercePlatform();
    fakeWooSystemStatus();

    $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload([
        'store_url' => WOO_HOST,
    ]));

    expect(Store::first()->external_store_id)->toBe('https://'.WOO_HOST);
});

test('connecting requires a business', function () {
    $user = makeBusinessUser();
    $user->update(['business_id' => null]);
    makeWooCommercePlatform();

    $this->actingAs($user)
        ->post(route('stores.connect.woocommerce.store'), wooConnectPayload())
        ->assertForbidden();
});

test('every credential field is required', function () {
    $user = makeBusinessUser();
    makeWooCommercePlatform();

    $this->actingAs($user)
        ->post(route('stores.connect.woocommerce.store'), [])
        ->assertSessionHasErrors(['name', 'store_url', 'consumer_key', 'consumer_secret']);
});

// ----------------------------------------------------------------
// SSRF guard
//
// WooCommerce is the only platform where the merchant supplies the host we
// then make server-side requests to, so these lock the vetting in place.
// ----------------------------------------------------------------

test('a loopback address is refused', function () {
    expect(fn () => WooCommerceStoreUrl::normalize('https://127.0.0.1'))
        ->toThrow(InvalidArgumentException::class);
});

test('a private network address is refused', function () {
    expect(fn () => WooCommerceStoreUrl::normalize('https://10.0.0.5'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => WooCommerceStoreUrl::normalize('https://192.168.1.1'))
        ->toThrow(InvalidArgumentException::class);
});

test('the cloud metadata address is refused', function () {
    // 169.254.169.254 is the AWS/GCP instance metadata endpoint — the single
    // highest-value SSRF target, since it hands out credentials.
    expect(fn () => WooCommerceStoreUrl::normalize('https://169.254.169.254'))
        ->toThrow(InvalidArgumentException::class);
});

test('an ipv6 loopback literal is refused', function () {
    expect(fn () => WooCommerceStoreUrl::normalize('https://[::1]'))
        ->toThrow(InvalidArgumentException::class);
});

test('a hostname that resolves to loopback is refused', function () {
    // The check is on the resolved address, not on the literal text — so a
    // name pointing at 127.0.0.1 is caught too.
    expect(fn () => WooCommerceStoreUrl::normalize('https://localhost'))
        ->toThrow(InvalidArgumentException::class);
});

test('plain http is refused', function () {
    // WooCommerce requires HTTPS for key/secret Basic Auth; over http the
    // credentials would be readable in transit.
    expect(fn () => WooCommerceStoreUrl::normalize('http://'.WOO_HOST))
        ->toThrow(InvalidArgumentException::class);
});

test('a non-default port is refused', function () {
    expect(fn () => WooCommerceStoreUrl::normalize('https://'.WOO_HOST.':6379'))
        ->toThrow(InvalidArgumentException::class);
});

test('an unresolvable domain is refused', function () {
    expect(fn () => WooCommerceStoreUrl::normalize('https://this-domain-does-not-exist-easyflow-test.invalid'))
        ->toThrow(InvalidArgumentException::class);
});

test('a public https domain is accepted', function () {
    expect(WooCommerceStoreUrl::normalize('https://'.WOO_HOST))->toBe('https://'.WOO_HOST);
});

test('the connect endpoint refuses a private address', function () {
    $user = makeBusinessUser();
    makeWooCommercePlatform();
    Http::fake();

    $response = $this->actingAs($user)->post(route('stores.connect.woocommerce.store'), wooConnectPayload([
        'store_url' => 'https://127.0.0.1',
    ]));

    $response->assertSessionHasErrors('store_url');
    expect(Store::count())->toBe(0);
    // Nothing was ever sent — the guard runs before any HTTP call.
    Http::assertNothingSent();
});
