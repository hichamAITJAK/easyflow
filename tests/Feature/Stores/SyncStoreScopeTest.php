<?php

use App\Enums\StoreConnectionStatus;
use App\Interfaces\EcomPlatformInterface;
use App\Models\EcommercePlatform;
use App\Models\Product;
use App\Models\Store;
use App\Services\Operations\IntegrationManagerService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Records which stores were asked to sync, so the tests assert on scope
 * rather than on how many rows a fake happened to return.
 */
class ScopeSpyPlatform implements EcomPlatformInterface
{
    /** @var array<int, int> */
    public static array $syncedStoreIds = [];

    public function __construct(private Store $store) {}

    public function getStoreDetails(): array
    {
        return [];
    }

    public function loadProducts(): array
    {
        self::$syncedStoreIds[] = $this->store->id;

        return [];
    }

    public function loadOrders(?CarbonInterface $since = null): array
    {
        self::$syncedStoreIds[] = $this->store->id;

        return [];
    }

    public function registerOrderWebhook(): void {}

    public function deregisterOrderWebhook(): void {}
}

beforeEach(function () {
    ScopeSpyPlatform::$syncedStoreIds = [];

    // Every store resolves to the spy, whatever its platform slug.
    app()->bind(IntegrationManagerService::class, fn () => new class extends IntegrationManagerService
    {
        public function ecomPlatformForStore(Store $store): EcomPlatformInterface
        {
            return new ScopeSpyPlatform($store);
        }
    });
});

function makeConnectedStore(int $businessId, string $name): Store
{
    $platform = EcommercePlatform::create([
        'name' => 'Shopify',
        'slug' => 'shopify-'.uniqid(),
    ]);

    $store = Store::create([
        'business_id' => $businessId,
        'platform_id' => $platform->id,
        'name' => $name,
        'api_credentials' => 'token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    // Orders sync refuses to run for a store with no products.
    Product::create([
        'business_id' => $businessId,
        'store_id' => $store->id,
        'external_product_id' => 'prod-'.$store->id,
        'name' => 'Product',
    ]);

    return $store;
}

test('loading products with no store_id syncs every connected store', function () {
    $admin = makeBusinessUser();
    $first = makeConnectedStore($admin->business_id, 'First');
    $second = makeConnectedStore($admin->business_id, 'Second');

    $this->actingAs($admin)
        ->post(route('products.sync'), ['platform' => '*'])
        ->assertRedirect();

    expect(ScopeSpyPlatform::$syncedStoreIds)
        ->toEqualCanonicalizing([$first->id, $second->id]);
});

test('loading products for one store syncs only that store', function () {
    $admin = makeBusinessUser();
    $target = makeConnectedStore($admin->business_id, 'Target');
    makeConnectedStore($admin->business_id, 'Other');

    $this->actingAs($admin)
        ->post(route('products.sync'), ['platform' => '*', 'store_id' => $target->id])
        ->assertRedirect();

    expect(ScopeSpyPlatform::$syncedStoreIds)->toBe([$target->id]);
});

test('loading orders with no store_id syncs every connected store', function () {
    $admin = makeBusinessUser();
    $first = makeConnectedStore($admin->business_id, 'First');
    $second = makeConnectedStore($admin->business_id, 'Second');

    $this->actingAs($admin)
        ->post(route('orders.sync'), ['platform' => '*'])
        ->assertRedirect();

    expect(ScopeSpyPlatform::$syncedStoreIds)
        ->toEqualCanonicalizing([$first->id, $second->id]);
});

test('loading orders for one store syncs only that store', function () {
    $admin = makeBusinessUser();
    $target = makeConnectedStore($admin->business_id, 'Target');
    makeConnectedStore($admin->business_id, 'Other');

    $this->actingAs($admin)
        ->post(route('orders.sync'), ['platform' => '*', 'store_id' => $target->id])
        ->assertRedirect();

    expect(ScopeSpyPlatform::$syncedStoreIds)->toBe([$target->id]);
});

test('a store_id belonging to another business syncs nothing', function () {
    // The tenant scope is applied before the id filter, so a forged id can
    // only ever narrow within the caller's own stores — never reach across.
    $admin = makeBusinessUser();
    makeConnectedStore($admin->business_id, 'Mine');

    $otherAdmin = makeBusinessUser();
    $foreign = makeConnectedStore($otherAdmin->business_id, 'Theirs');

    $this->actingAs($admin)
        ->post(route('orders.sync'), ['platform' => '*', 'store_id' => $foreign->id])
        ->assertRedirect();

    expect(ScopeSpyPlatform::$syncedStoreIds)->toBe([]);
});

test('a disconnected store is never synced even when named explicitly', function () {
    $admin = makeBusinessUser();
    $store = makeConnectedStore($admin->business_id, 'Pending');
    $store->update(['connection_status' => StoreConnectionStatus::PENDING]);

    $this->actingAs($admin)
        ->post(route('products.sync'), ['platform' => '*', 'store_id' => $store->id])
        ->assertRedirect();

    expect(ScopeSpyPlatform::$syncedStoreIds)->toBe([]);
});
