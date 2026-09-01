<?php

use App\Enums\StoreConnectionStatus;
use App\Interfaces\EcomPlatformInterface;
use App\Models\EcommercePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Operations\EcomPlatforms\LightfunnelsService;
use App\Services\Operations\IntegrationManagerService;
use App\Services\Operations\Orders\OrderSyncService;
use App\Services\Operations\Products\ProductSyncService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** Minimal Arrayable stand-in for a platform DTO. */
class FakeAsset implements Arrayable
{
    public function __construct(private array $data) {}

    public function toArray(): array
    {
        return $this->data;
    }
}

/**
 * Records the `$since` it was handed so tests can assert the cutoff is
 * actually passed down to the platform, and returns a fixed asset list so
 * the sync services' own filtering is what's under test.
 */
class FakePlatform implements EcomPlatformInterface
{
    public ?CarbonInterface $ordersSince = null;

    public function __construct(
        private array $products = [],
        private array $orders = [],
    ) {}

    public function getStoreDetails(): array
    {
        return [];
    }

    public function loadProducts(): array
    {
        return $this->products;
    }

    public function loadOrders(?CarbonInterface $since = null): array
    {
        $this->ordersSince = $since;

        return $this->orders;
    }

    public function registerOrderWebhook(): void {}

    public function deregisterOrderWebhook(): void {}
}

function makeSyncStore(string $platformSlug = 'shopify'): Store
{
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create([
        'name' => ucfirst($platformSlug),
        'slug' => $platformSlug,
    ]);

    $store = Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'Store '.uniqid(),
        'api_credentials' => 'token',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    // The cutoff is the store's creation moment; pin it so the fixtures
    // below sit unambiguously either side of it.
    $store->forceFill(['created_at' => '2026-06-01 00:00:00'])->save();

    return $store->fresh()->load('platform');
}

/**
 * OrderSyncService refuses to sync orders for a store with no products
 * (line items match against them), so order-focused tests need at least one.
 */
function seedProductFor(Store $store): Product
{
    return Product::create([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'prod-1',
        'name' => 'Product',
    ]);
}

function managerReturning(FakePlatform $platform): IntegrationManagerService
{
    return new class($platform) extends IntegrationManagerService
    {
        public function __construct(private FakePlatform $fake) {}

        public function ecomPlatformForStore(Store $store): EcomPlatformInterface
        {
            return $this->fake;
        }
    };
}

test('the full product catalog is imported regardless of creation date', function () {
    // Products are reference data: an order placed today can reference a
    // product created years ago, and line items match on external product
    // id. Date-filtering the catalog would leave those items unlinked.
    $store = makeSyncStore();
    $platform = new FakePlatform(products: [
        new FakeAsset(['id' => 'old', 'title' => 'Old Product', 'created_at' => '2025-01-01T00:00:00Z']),
        new FakeAsset(['id' => 'new', 'title' => 'New Product', 'created_at' => '2026-07-01T00:00:00Z']),
    ]);

    $synced = (new ProductSyncService(managerReturning($platform)))->syncStore($store);

    expect($synced)->toBe(2);
    expect(Product::pluck('external_product_id')->sort()->values()->all())
        ->toBe(['new', 'old']);
});

test('an order is linked to a product that predates the store connection', function () {
    // The reason products are not date-filtered, stated as behaviour: a new
    // order referencing an old product must still resolve its line item.
    $store = makeSyncStore();

    (new ProductSyncService(managerReturning(new FakePlatform(products: [
        new FakeAsset(['id' => 'legacy-prod', 'title' => 'Legacy', 'created_at' => '2024-01-01T00:00:00Z']),
    ]))))->syncStore($store);

    $platform = new FakePlatform(orders: [
        new FakeAsset([
            'id' => 'new-order',
            'customer_name' => 'Customer',
            'customer_phone' => '+212600000004',
            'total' => 100,
            'created_at' => '2026-07-15T00:00:00Z',
            'line_items' => [
                ['product_id' => 'legacy-prod', 'title' => 'Legacy', 'quantity' => 1],
            ],
        ]),
    ]);

    (new OrderSyncService(managerReturning($platform)))->syncStore($store);

    $item = Order::where('external_order_id', 'new-order')->firstOrFail()->items()->firstOrFail();

    expect($item->product_id)->not->toBeNull();
});

test('orders placed before the store was connected are not imported', function () {
    $store = makeSyncStore();
    seedProductFor($store);

    $platform = new FakePlatform(orders: [
        new FakeAsset([
            'id' => 'old-order',
            'customer_name' => 'Old Customer',
            'customer_phone' => '+212600000001',
            'total' => 100,
            'created_at' => '2025-05-01T00:00:00Z',
            'line_items' => [],
        ]),
        new FakeAsset([
            'id' => 'new-order',
            'customer_name' => 'New Customer',
            'customer_phone' => '+212600000002',
            'total' => 200,
            'created_at' => '2026-07-15T00:00:00Z',
            'line_items' => [],
        ]),
    ]);

    $synced = (new OrderSyncService(managerReturning($platform)))->syncStore($store);

    expect($synced)->toBe(1);
    expect(Order::pluck('external_order_id')->all())->toBe(['new-order']);
});

test('the store connection date is passed down to the platform as the cutoff', function () {
    $store = makeSyncStore();
    $platform = new FakePlatform;

    (new OrderSyncService(managerReturning($platform)))->syncStore($store);

    expect($platform->ordersSince?->toDateTimeString())->toBe('2026-06-01 00:00:00');
});

test('an order dated exactly at the cutoff is imported', function () {
    $store = makeSyncStore();
    seedProductFor($store);
    $platform = new FakePlatform(orders: [
        new FakeAsset([
            'id' => 'boundary',
            'customer_name' => 'Boundary',
            'customer_phone' => '+212600000005',
            'total' => 10,
            'created_at' => '2026-06-01T00:00:00Z',
            'line_items' => [],
        ]),
    ]);

    (new OrderSyncService(managerReturning($platform)))->syncStore($store);

    expect(Order::pluck('external_order_id')->all())->toBe(['boundary']);
});

test('an order with no creation date is kept rather than silently dropped', function () {
    // Losing a real order we simply can't date is worse than importing an
    // old one, so undated/unparseable orders fall through the cutoff.
    $store = makeSyncStore();
    seedProductFor($store);
    $platform = new FakePlatform(orders: [
        new FakeAsset([
            'id' => 'undated',
            'customer_name' => 'Undated',
            'customer_phone' => '+212600000006',
            'total' => 10,
            'line_items' => [],
        ]),
        new FakeAsset([
            'id' => 'garbage',
            'customer_name' => 'Garbage',
            'customer_phone' => '+212600000007',
            'total' => 10,
            'created_at' => 'not-a-date',
            'line_items' => [],
        ]),
    ]);

    (new OrderSyncService(managerReturning($platform)))->syncStore($store);

    expect(Order::pluck('external_order_id')->sort()->values()->all())
        ->toBe(['garbage', 'undated']);
});

test('youcan epoch timestamps are compared against the cutoff correctly', function () {
    // YouCan sends created_at as a Unix epoch int, not an ISO string — the
    // wrong parse here would make every order look like 1970 and get dropped.
    $store = makeSyncStore('youcan');
    seedProductFor($store);

    $platform = new FakePlatform(orders: [
        new FakeAsset([
            'id' => 'old-order',
            'customer_name' => 'Old',
            'customer_phone' => '+212600000001',
            'total' => 100,
            // 2025-05-01
            'created_at' => 1746057600,
            'line_items' => [],
        ]),
        new FakeAsset([
            'id' => 'new-order',
            'customer_name' => 'New',
            'customer_phone' => '+212600000002',
            'total' => 200,
            // 2026-07-15
            'created_at' => 1784073600,
            'line_items' => [],
        ]),
    ]);

    (new OrderSyncService(managerReturning($platform)))->syncStore($store);

    expect(Order::pluck('external_order_id')->all())->toBe(['new-order']);
});

test('lightfunnels sends the cutoff as a created_at term without losing its ordering', function () {
    // `created_at` is a documented filter parameter for the orders query,
    // and `order_by:id` must survive alongside it — it drives the cursor
    // pagination, so replacing rather than appending would break paging.
    $store = makeSyncStore('lightfunnels');
    $store->forceFill(['api_credentials' => 'token'])->save();

    Http::fake([
        '*' => Http::response(['data' => ['orders' => [
            'edges' => [],
            'pageInfo' => ['endCursor' => '', 'hasNextPage' => false],
        ]]]),
    ]);

    (new LightfunnelsService($store->fresh()->load('platform')))->loadOrders(
        Carbon::parse('2026-06-01 00:00:00')
    );

    Http::assertSent(function ($request) {
        $query = $request->data()['variables']['query'] ?? '';

        return str_contains($query, 'order_by:id')
            && str_contains($query, 'created_at:>=2026-06-01');
    });
});

test('a webhook order is never rejected by the cutoff', function () {
    // syncOne() is the webhook path: a live order arriving now must always
    // be accepted, regardless of what date the payload carries.
    $store = makeSyncStore();

    $order = (new OrderSyncService(managerReturning(new FakePlatform)))->syncOne($store, [
        'id' => 'webhook-order',
        'customer_name' => 'Live Customer',
        'customer_phone' => '+212600000003',
        'total' => 50,
        'created_at' => '2020-01-01T00:00:00Z',
        'line_items' => [],
    ]);

    expect($order->external_order_id)->toBe('webhook-order');
});

test('a locally-edited variant sku survives a re-sync', function () {
    // Variant SKUs are local courier-mapping data, never pushed to the
    // platform — so a re-sync must not clobber an operator's edit. Every
    // other variant field is platform-owned and is expected to be
    // overwritten.
    $store = makeSyncStore();

    $payload = fn (string $sku, string $price) => new FakeAsset([
        'id' => 'prod-1',
        'title' => 'Tee',
        'variants' => [
            ['id' => 'v-1', 'sku' => $sku, 'price' => $price, 'inventory_quantity' => 5],
        ],
    ]);

    $service = fn (FakeAsset $asset) => (new ProductSyncService(
        managerReturning(new FakePlatform(products: [$asset])),
    ))->syncStore($store);

    // First sync seeds the SKU from the platform.
    $service($payload('PLATFORM-SKU', '100'));

    $variant = ProductVariant::firstWhere('external_variant_id', 'v-1');
    expect($variant->sku)->toBe('PLATFORM-SKU');

    // Operator remaps it for their courier.
    $variant->update(['sku' => 'COURIER-SKU']);

    // Platform re-sends its own SKU and a new price.
    $service($payload('PLATFORM-SKU', '150'));

    $variant->refresh();
    expect($variant->sku)->toBe('COURIER-SKU');
    expect((float) $variant->price)->toBe(150.0);
});
