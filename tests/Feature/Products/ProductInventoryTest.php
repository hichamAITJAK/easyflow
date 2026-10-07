<?php

use App\Enums\StoreConnectionStatus;
use App\Enums\UserRole;
use App\Models\EcommercePlatform;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Operations\Products\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function productWithVariants(int $businessId): Product
{
    $product = Product::factory()->create(['business_id' => $businessId, 'inventory_quantity' => 3]);

    foreach ([5, 8] as $quantity) {
        ProductVariant::factory()->create([
            'business_id' => $businessId,
            'product_id' => $product->id,
            'inventory_quantity' => $quantity,
        ]);
    }

    return $product;
}

test('an admin can set each variant\'s stock', function () {
    $admin = makeBusinessUser();
    $product = productWithVariants($admin->business_id);
    [$first, $second] = $product->variants()->orderBy('id')->get();

    $this->actingAs($admin)
        ->patch(route('products.inventory', $product), [
            'variants' => [$first->id => 12, $second->id => 0],
        ])
        ->assertRedirect();

    expect($first->refresh()->inventory_quantity)->toBe(12)
        ->and($second->refresh()->inventory_quantity)->toBe(0)
        ->and($product->refresh()->stock_managed_locally)->toBeTrue();
});

test('a fulfilment agent can edit stock, a confirmation agent cannot', function () {
    $admin = makeBusinessUser();
    $product = Product::factory()->create(['business_id' => $admin->business_id, 'inventory_quantity' => 3]);

    $fulfilment = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT, 'business_id' => $admin->business_id]);
    $this->actingAs($fulfilment)
        ->patch(route('products.inventory', $product), ['inventory_quantity' => 40])
        ->assertRedirect();
    expect($product->refresh()->inventory_quantity)->toBe(40);

    // They can also open the catalogue to get there.
    $this->actingAs($fulfilment)->get(route('products.index'))->assertOk();

    $confirmer = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $this->actingAs($confirmer)
        ->patch(route('products.inventory', $product), ['inventory_quantity' => 1])
        ->assertForbidden();
});

test('stock cannot be edited across businesses or through a forged variant id', function () {
    $admin = makeBusinessUser();
    $product = productWithVariants($admin->business_id);

    // The business scope hides the product entirely: 404, never a write.
    $otherAdmin = makeBusinessUser();
    $this->actingAs($otherAdmin)
        ->patch(route('products.inventory', $product), ['inventory_quantity' => 1])
        ->assertNotFound();
    expect($product->refresh()->inventory_quantity)->toBe(3);

    $foreignVariant = productWithVariants($otherAdmin->business_id)->variants()->first();
    $this->actingAs($admin)
        ->patch(route('products.inventory', $product), ['variants' => [$foreignVariant->id => 99]])
        ->assertRedirect();

    expect($foreignVariant->refresh()->inventory_quantity)->not->toBe(99);
});

test('a store sync leaves locally managed stock alone', function () {
    $user = makeBusinessUser();
    $platform = EcommercePlatform::create(['name' => 'YouCan', 'slug' => 'YouCan']);
    $store = Store::create([
        'business_id' => $user->business_id,
        'platform_id' => $platform->id,
        'name' => 'shop',
        'external_store_id' => 'shop',
        'api_credentials' => json_encode(['access_token' => 'ACCESS-1']),
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    Http::fake(fn () => Http::response([
        'data' => [[
            'id' => 'p-1',
            'name' => 'Robe',
            'price' => 100,
            'visibility' => true,
            'variants' => [
                ['id' => 'v-1', 'variations' => ['Couleur' => 'Bleu'], 'price' => 100, 'inventory' => 7],
            ],
        ]],
        'meta' => ['pagination' => ['current_page' => 1, 'total_pages' => 1]],
    ]));

    $sync = app(ProductSyncService::class);
    $sync->syncStore($store);

    $product = Product::withoutGlobalScopes()->where('store_id', $store->id)->firstOrFail();
    $variant = $product->variants()->firstOrFail();
    expect($variant->inventory_quantity)->toBe(7);

    // Warehouse count entered in EasyFlow…
    $this->actingAs($user)
        ->patch(route('products.inventory', $product), ['variants' => [$variant->id => 2]])
        ->assertRedirect();

    // …survives the next sync, which still refreshes the rest.
    $sync->syncStore($store);

    expect($variant->refresh()->inventory_quantity)->toBe(2);
});
