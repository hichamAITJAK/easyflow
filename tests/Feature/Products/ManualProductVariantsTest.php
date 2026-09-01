<?php

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('creating a manual product with options generates a variant per combination', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('products.store'), [
        'name' => 'T-Shirt',
        'price' => '199',
        'options' => [
            ['name' => 'Size', 'values' => ['S', 'M']],
            ['name' => 'Color', 'values' => ['Red', 'Blue']],
        ],
        'variants' => [
            ['options' => ['Size' => 'S', 'Color' => 'Red'], 'sku' => 'TS-S-RED', 'price' => '199', 'inventory_quantity' => 10],
            ['options' => ['Size' => 'S', 'Color' => 'Blue'], 'sku' => 'TS-S-BLU', 'price' => '199', 'inventory_quantity' => 5],
            ['options' => ['Size' => 'M', 'Color' => 'Red'], 'sku' => 'TS-M-RED', 'price' => '209', 'inventory_quantity' => 8],
            ['options' => ['Size' => 'M', 'Color' => 'Blue'], 'sku' => 'TS-M-BLU', 'price' => '209', 'inventory_quantity' => 3],
        ],
    ])->assertRedirect(route('products.index'));

    $product = Product::where('business_id', $admin->business_id)->firstOrFail();

    expect($product->store_id)->toBeNull();
    expect($product->options()->pluck('name')->all())->toEqualCanonicalizing(['Size', 'Color']);
    expect($product->variants()->count())->toBe(4);

    $variant = $product->variants()->where('sku', 'TS-M-RED')->firstOrFail();
    expect($variant->inventory_quantity)->toBe(8);
    expect($variant->optionValues()->pluck('value')->all())->toEqualCanonicalizing(['M', 'Red']);
});

test('updating a manual product reconciles the variant set, preserving unchanged combinations', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('products.store'), [
        'name' => 'Mug',
        'price' => '49',
        'options' => [['name' => 'Color', 'values' => ['Red', 'Blue']]],
        'variants' => [
            ['options' => ['Color' => 'Red'], 'sku' => 'MUG-RED', 'inventory_quantity' => 10],
            ['options' => ['Color' => 'Blue'], 'sku' => 'MUG-BLU', 'inventory_quantity' => 4],
        ],
    ]);

    $product = Product::where('business_id', $admin->business_id)->firstOrFail();
    $redId = $product->variants()->where('sku', 'MUG-RED')->value('id');

    // Drop Blue, keep Red (unchanged), add Green.
    $this->actingAs($admin)->patch(route('products.update', $product), [
        'name' => 'Mug',
        'price' => '49',
        'options' => [['name' => 'Color', 'values' => ['Red', 'Green']]],
        'variants' => [
            ['options' => ['Color' => 'Red'], 'sku' => 'MUG-RED', 'inventory_quantity' => 10],
            ['options' => ['Color' => 'Green'], 'sku' => 'MUG-GRN', 'inventory_quantity' => 7],
        ],
    ])->assertRedirect();

    expect($product->variants()->pluck('sku')->all())->toEqualCanonicalizing(['MUG-RED', 'MUG-GRN']);
    // The unchanged Red combination keeps its original row (and any order links).
    expect($product->variants()->where('sku', 'MUG-RED')->value('id'))->toBe($redId);
    // Blue was soft-deleted, not lingering as active.
    expect(ProductVariant::withTrashed()->where('sku', 'MUG-BLU')->first()->trashed())->toBeTrue();
});

test('a variant image is persisted alongside its other attributes', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('products.store'), [
        'name' => 'T-Shirt',
        'price' => '199',
        'options' => [['name' => 'Color', 'values' => ['Red']]],
        'variants' => [
            ['options' => ['Color' => 'Red'], 'sku' => 'TS-RED', 'image' => 'https://example.com/red.jpg'],
        ],
    ])->assertRedirect(route('products.index'));

    $product = Product::where('business_id', $admin->business_id)->firstOrFail();
    $variant = $product->variants()->where('sku', 'TS-RED')->firstOrFail();

    expect($variant->image)->toBe('https://example.com/red.jpg');
});

test('a manual product gallery is persisted and its first image becomes the thumbnail', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('products.store'), [
        'name' => 'T-Shirt',
        'price' => '199',
        'images' => ['product-images/shirt-front.jpg', 'product-images/shirt-back.jpg'],
    ])->assertRedirect(route('products.index'));

    $product = Product::where('business_id', $admin->business_id)->firstOrFail();

    $paths = $product->images()->orderBy('position')->get()
        ->map(fn ($image) => $image->getRawOriginal('path'))->all();

    expect($paths)->toBe([
        'product-images/shirt-front.jpg',
        'product-images/shirt-back.jpg',
    ]);
    expect($product->getRawOriginal('thumbnail'))->toBe('product-images/shirt-front.jpg');
    expect($product->thumbnail)->toBe('/storage/product-images/shirt-front.jpg');
});

test('updating a manual product gallery reorders the thumbnail and drops removed images', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('products.store'), [
        'name' => 'Mug',
        'price' => '49',
        'images' => ['product-images/a.jpg', 'product-images/b.jpg'],
    ]);

    $product = Product::where('business_id', $admin->business_id)->firstOrFail();

    $this->actingAs($admin)->patch(route('products.update', $product), [
        'name' => 'Mug',
        'price' => '49',
        'images' => ['product-images/b.jpg'],
    ])->assertRedirect();

    $paths = $product->images()->get()
        ->map(fn ($image) => $image->getRawOriginal('path'))->all();

    expect($paths)->toBe(['product-images/b.jpg']);
    expect($product->fresh()->getRawOriginal('thumbnail'))->toBe('product-images/b.jpg');
});

test('a manual product can be deleted', function () {
    $admin = makeBusinessUser();

    $product = Product::create([
        'business_id' => $admin->business_id,
        'name' => 'Manual Product',
    ]);

    $this->actingAs($admin)->delete(route('products.destroy', $product))
        ->assertRedirect(route('products.index'));

    expect(Product::find($product->id))->toBeNull();
    expect(Product::withTrashed()->find($product->id))->not->toBeNull();
});

test('a synced product cannot be deleted', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);

    $product = Product::create([
        'business_id' => $admin->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'ext-1',
        'name' => 'Synced Product',
    ]);

    $this->actingAs($admin)->delete(route('products.destroy', $product))
        ->assertForbidden();

    expect(Product::find($product->id))->not->toBeNull();
});

test('a product cannot be deleted by a user from another business', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();

    $product = Product::create([
        'business_id' => $otherAdmin->business_id,
        'name' => 'Other Business Product',
    ]);

    // BusinessScope filters route-model-binding to the caller's own
    // business, so a cross-business id 404s rather than reaching the
    // controller's own business_id check (which would 403).
    $this->actingAs($admin)->delete(route('products.destroy', $product))
        ->assertNotFound();

    expect(Product::withoutGlobalScopes()->find($product->id))->not->toBeNull();
});

test('a synced product update ignores variant payload and only accepts sku', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);

    $product = Product::create([
        'business_id' => $admin->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'ext-1',
        'name' => 'Synced Tee',
    ]);

    $this->actingAs($admin)->patch(route('products.update', $product), [
        'sku' => 'NEW-SKU',
        'name' => 'Hacked name',
        'options' => [['name' => 'Size', 'values' => ['S']]],
        'variants' => [['options' => ['Size' => 'S'], 'sku' => 'X']],
    ])->assertRedirect();

    $product->refresh();
    expect($product->sku)->toBe('NEW-SKU');
    expect($product->name)->toBe('Synced Tee');
    expect($product->variants()->count())->toBe(0);
    expect($product->options()->count())->toBe(0);
});

test('a synced product variant sku is editable and blanking it clears the mapping', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);

    $product = Product::create([
        'business_id' => $admin->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'ext-1',
        'name' => 'Synced Tee',
    ]);

    $small = ProductVariant::create([
        'business_id' => $admin->business_id,
        'product_id' => $product->id,
        'external_variant_id' => 'v-1',
        'sku' => 'PLATFORM-S',
    ]);

    $large = ProductVariant::create([
        'business_id' => $admin->business_id,
        'product_id' => $product->id,
        'external_variant_id' => 'v-2',
        'sku' => 'PLATFORM-L',
    ]);

    $this->actingAs($admin)->patch(route('products.update', $product), [
        'variant_skus' => [
            $small->id => 'COURIER-S',
            $large->id => '',
        ],
    ])->assertRedirect();

    expect($small->refresh()->sku)->toBe('COURIER-S');
    expect($large->refresh()->sku)->toBeNull();
});

test('a variant sku edit cannot reach a variant on another product', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);

    $product = Product::create([
        'business_id' => $admin->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'ext-1',
        'name' => 'Synced Tee',
    ]);

    // BusinessScope on ProductVariant is what actually stops this one — the
    // controller's relation scoping is never reached. The sibling-product
    // test below is the one that exercises that. Kept as a regression guard
    // in case the global scope is ever loosened.
    //
    // The caller's product owns a variant so the foreign id can't match
    // nothing merely by coincidence of id numbering.
    $own = ProductVariant::create([
        'business_id' => $admin->business_id,
        'product_id' => $product->id,
        'external_variant_id' => 'v-1',
        'sku' => 'MINE',
    ]);

    $otherAdmin = makeBusinessUser();
    $otherStore = makeStore($otherAdmin->business_id);
    $otherProduct = Product::create([
        'business_id' => $otherAdmin->business_id,
        'store_id' => $otherStore->id,
        'external_product_id' => 'ext-2',
        'name' => 'Someone else\'s tee',
    ]);

    $foreignVariant = ProductVariant::create([
        'business_id' => $otherAdmin->business_id,
        'product_id' => $otherProduct->id,
        'external_variant_id' => 'v-9',
        'sku' => 'THEIRS',
    ]);

    $this->actingAs($admin)->patch(route('products.update', $product), [
        'variant_skus' => [$foreignVariant->id => 'STOLEN'],
    ])->assertRedirect();

    expect($foreignVariant->refresh()->sku)->toBe('THEIRS');
    // And the write didn't land on the caller's own variant instead.
    expect($own->refresh()->sku)->toBe('MINE');
});

test('a variant sku edit cannot reach a sibling product in the same business', function () {
    // BusinessScope already blocks cross-tenant reads, so the cross-business
    // case can't exercise the controller's own scoping. Two products in the
    // SAME business can: only the relation-scoped query rejects this.
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);

    $target = Product::create([
        'business_id' => $admin->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'ext-1',
        'name' => 'Edited product',
    ]);

    $sibling = Product::create([
        'business_id' => $admin->business_id,
        'store_id' => $store->id,
        'external_product_id' => 'ext-2',
        'name' => 'Untouched sibling',
    ]);

    $siblingVariant = ProductVariant::create([
        'business_id' => $admin->business_id,
        'product_id' => $sibling->id,
        'external_variant_id' => 'v-sibling',
        'sku' => 'SIBLING',
    ]);

    $this->actingAs($admin)->patch(route('products.update', $target), [
        'variant_skus' => [$siblingVariant->id => 'LEAKED'],
    ])->assertRedirect();

    expect($siblingVariant->refresh()->sku)->toBe('SIBLING');
});
