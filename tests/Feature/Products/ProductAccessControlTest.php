<?php

use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeProduct(int $businessId, int $storeId, array $overrides = []): Product
{
    return Product::create([
        'business_id' => $businessId,
        'store_id' => $storeId,
        'name' => 'Product '.uniqid(),
        ...$overrides,
    ]);
}

test('an agent with a store-scoped AgentScope only sees that store\'s products', function () {
    $admin = makeBusinessUser();
    $storeA = makeStore($admin->business_id);
    $storeB = makeStore($admin->business_id);

    $productA = makeProduct($admin->business_id, $storeA->id);
    makeProduct($admin->business_id, $storeB->id);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    AgentScope::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'store_id' => $storeA->id,
        'product_id' => null,
    ]);

    $response = $this->actingAs($agent)->get(route('products.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('products/index')
        ->has('products.data', 1)
        ->where('products.data.0.id', $productA->id)
        ->has('stores', 1)
        ->where('stores.0.id', $storeA->id)
    );
});

test('an agent with a product-scoped AgentScope sees that product regardless of store scope', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);

    $scopedProduct = makeProduct($admin->business_id, $store->id);
    makeProduct($admin->business_id, $store->id);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    AgentScope::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'store_id' => null,
        'product_id' => $scopedProduct->id,
    ]);

    $response = $this->actingAs($agent)->get(route('products.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.id', $scopedProduct->id)
    );
});

test('an agent with no AgentScope rows sees every business product', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);
    makeProduct($admin->business_id, $store->id);
    makeProduct($admin->business_id, $store->id);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $response = $this->actingAs($agent)->get(route('products.index'));

    $response->assertInertia(fn ($page) => $page->has('products.data', 2));
});

test('an admin sees every business product regardless of AgentScope', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);
    makeProduct($admin->business_id, $store->id);
    makeProduct($admin->business_id, $store->id);

    $response = $this->actingAs($admin)->get(route('products.index'));

    $response->assertInertia(fn ($page) => $page->has('products.data', 2));
});

test('marking a product as test toggles the flag locally without touching other fields', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);
    $product = makeProduct($admin->business_id, $store->id);

    expect($product->refresh()->is_test)->toBeFalse();

    $this->actingAs($admin)->patch(route('products.test', $product))->assertRedirect();
    expect($product->refresh()->is_test)->toBeTrue();

    $this->actingAs($admin)->patch(route('products.test', $product))->assertRedirect();
    expect($product->refresh()->is_test)->toBeFalse();
});

test('marking another business\'s product as test is not found', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();
    $otherStore = makeStore($otherAdmin->business_id);
    $otherProduct = makeProduct($otherAdmin->business_id, $otherStore->id);

    $this->actingAs($admin)->patch(route('products.test', $otherProduct))->assertNotFound();
});

test('an agent cannot reach any product write route', function (string $method, string $route, bool $needsProduct) {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);
    $product = makeProduct($admin->business_id, $store->id, ['store_id' => null]);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $url = $needsProduct ? route($route, $product) : route($route);

    $this->actingAs($agent)->{$method}($url)->assertForbidden();
})->with([
    'create form' => ['get', 'products.create', false],
    'load products' => ['get', 'products.load-products', false],
    'sync' => ['post', 'products.sync', false],
    'store' => ['post', 'products.store', false],
    'edit form' => ['get', 'products.edit', true],
    'update' => ['patch', 'products.update', true],
    'mark test' => ['patch', 'products.test', true],
    'variant image' => ['post', 'products.variant-image', false],
    'image' => ['post', 'products.image', false],
    'destroy' => ['delete', 'products.destroy', true],
]);

test('an admin still reaches the product write routes the gate guards', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);
    $product = makeProduct($admin->business_id, $store->id);

    $this->actingAs($admin)->get(route('products.create'))->assertOk();
    $this->actingAs($admin)->patch(route('products.test', $product))->assertRedirect();
});

test('an agent still reads the catalogue and a scoped product\'s variants', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);
    $product = makeProduct($admin->business_id, $store->id);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($agent)->get(route('products.index'))->assertOk();
    $this->actingAs($agent)->get(route('products.variants', $product))
        ->assertOk()
        ->assertJsonStructure(['variants']);
});

test('a scoped agent cannot read variants of a product outside their scope', function () {
    $admin = makeBusinessUser();
    $storeA = makeStore($admin->business_id);
    $storeB = makeStore($admin->business_id);

    $outOfScope = makeProduct($admin->business_id, $storeB->id);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    AgentScope::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'store_id' => $storeA->id,
        'product_id' => null,
    ]);

    $this->actingAs($agent)->get(route('products.variants', $outOfScope))->assertForbidden();
});
