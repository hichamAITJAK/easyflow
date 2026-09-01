<?php

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('creating a manual order with a variant records the variant and its sku snapshot', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('products.store'), [
        'name' => 'T-Shirt',
        'price' => '199',
        'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
        'variants' => [
            ['options' => ['Size' => 'S'], 'sku' => 'TS-S', 'price' => '199'],
            ['options' => ['Size' => 'M'], 'sku' => 'TS-M', 'price' => '209'],
        ],
    ]);

    $product = Product::where('business_id', $admin->business_id)->firstOrFail();
    $variant = $product->variants()->where('sku', 'TS-M')->firstOrFail();

    $response = $this->actingAs($admin)->post(route('orders.store'), [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 209,
        'items' => [
            [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 209,
            ],
        ],
    ]);

    $response->assertRedirect(route('orders.index'));

    $order = Order::where('business_id', $admin->business_id)->firstOrFail();
    $item = $order->items()->firstOrFail();

    expect($item->product_variant_id)->toBe($variant->id);
    expect($item->sku_snapshot)->toBe('TS-M');
});

test('one product can be ordered in several variants, each with its own quantity', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('products.store'), [
        'name' => 'T-Shirt',
        'price' => '199',
        'options' => [['name' => 'Size', 'values' => ['S', 'M', 'L']]],
        'variants' => [
            ['options' => ['Size' => 'S'], 'sku' => 'TS-S', 'price' => '199'],
            ['options' => ['Size' => 'M'], 'sku' => 'TS-M', 'price' => '209'],
            ['options' => ['Size' => 'L'], 'sku' => 'TS-L', 'price' => '219'],
        ],
    ]);

    $product = Product::where('business_id', $admin->business_id)->firstOrFail();
    $variants = $product->variants()->get()->keyBy('sku');

    // The form sends one entry per variant, all sharing a product_id — the
    // shape a single on-screen line with three variant rows flattens to.
    $response = $this->actingAs($admin)->post(route('orders.store'), [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 2 * 199 + 209 + 3 * 219,
        'items' => [
            ['product_id' => $product->id, 'product_variant_id' => $variants['TS-S']->id, 'product_name' => 'T-Shirt', 'quantity' => 2, 'unit_price' => 199],
            ['product_id' => $product->id, 'product_variant_id' => $variants['TS-M']->id, 'product_name' => 'T-Shirt', 'quantity' => 1, 'unit_price' => 209],
            ['product_id' => $product->id, 'product_variant_id' => $variants['TS-L']->id, 'product_name' => 'T-Shirt', 'quantity' => 3, 'unit_price' => 219],
        ],
    ]);

    $response->assertRedirect(route('orders.index'));

    $order = Order::where('business_id', $admin->business_id)->firstOrFail();
    $items = $order->items()->get();

    expect($items)->toHaveCount(3);
    expect($items->pluck('quantity', 'sku_snapshot')->all())
        ->toBe(['TS-S' => 2, 'TS-M' => 1, 'TS-L' => 3]);
    // Every row is the same product; only the variant differs.
    expect($items->pluck('product_id')->unique()->all())->toBe([$product->id]);
});

test('the product picker exposes stock at both the product and variant level', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('products.store'), [
        'name' => 'T-Shirt',
        'price' => '199',
        'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
        'variants' => [
            ['options' => ['Size' => 'S'], 'sku' => 'TS-S', 'price' => '199'],
            ['options' => ['Size' => 'M'], 'sku' => 'TS-M', 'price' => '209'],
        ],
    ]);

    $product = Product::where('business_id', $admin->business_id)->firstOrFail();
    $product->update(['inventory_quantity' => 12]);
    $product->variants()->where('sku', 'TS-S')->update(['inventory_quantity' => 3]);
    $product->variants()->where('sku', 'TS-M')->update(['inventory_quantity' => null]);

    $payload = $this->actingAs($admin)
        ->getJson(route('orders.products'))
        ->assertOk()
        ->json('products.0');

    // The quantity field is driven entirely by these numbers, so a product
    // selected without inventory_quantity in the column list would silently
    // uncap every line.
    expect($payload['inventory_quantity'])->toBe(12);

    $bySku = collect($payload['variants'])->keyBy('sku');

    expect($bySku['TS-S']['inventory_quantity'])->toBe(3);
    // Untracked stays null rather than collapsing to 0 — the form reads null
    // as "no limit", and 0 would wrongly mark the variant sold out.
    expect($bySku['TS-M']['inventory_quantity'])->toBeNull();
});

test('a manually created order rejects a variant belonging to another business', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();

    $this->actingAs($otherAdmin)->post(route('products.store'), [
        'name' => 'Mug',
        'price' => '49',
        'options' => [['name' => 'Color', 'values' => ['Red']]],
        'variants' => [['options' => ['Color' => 'Red'], 'sku' => 'MUG-RED']],
    ]);

    $otherVariant = Product::where('business_id', $otherAdmin->business_id)
        ->firstOrFail()
        ->variants()
        ->firstOrFail();

    $response = $this->actingAs($admin)->post(route('orders.store'), [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
        'items' => [
            [
                'product_variant_id' => $otherVariant->id,
                'product_name' => 'Mug',
                'quantity' => 1,
                'unit_price' => 100,
            ],
        ],
    ]);

    $response->assertSessionHasErrors('items.0.product_variant_id');
});
