<?php

use App\Enums\Courier;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Store and courier list filters accept several selections at once
 * (?store_ids=3,7). The superseded singular params (?store_id=3) are still
 * honoured so links saved before the change keep working — these tests pin
 * both halves of that contract, plus the echoed value the picker binds to.
 */
test('the orders list filters by several stores at once', function () {
    $admin = makeBusinessUser();
    $alpha = makeStore($admin->business_id, ['name' => 'Alpha']);
    $beta = makeStore($admin->business_id, ['name' => 'Beta']);
    $gamma = makeStore($admin->business_id, ['name' => 'Gamma']);

    makeOrder($admin->business_id, ['store_id' => $alpha->id, 'reference' => 'A-1']);
    makeOrder($admin->business_id, ['store_id' => $beta->id, 'reference' => 'B-1']);
    makeOrder($admin->business_id, ['store_id' => $gamma->id, 'reference' => 'G-1']);

    $this->actingAs($admin)
        ->get(route('orders.index', ['store_ids' => "{$alpha->id},{$beta->id}"]))
        ->assertInertia(fn ($page) => $page
            ->has('orders.data', 2)
            ->where('filters.store_ids', "{$alpha->id},{$beta->id}")
            ->etc()
        );
});

test('the orders list still honours a legacy single store_id link', function () {
    $admin = makeBusinessUser();
    $alpha = makeStore($admin->business_id, ['name' => 'Alpha']);
    $beta = makeStore($admin->business_id, ['name' => 'Beta']);

    makeOrder($admin->business_id, ['store_id' => $alpha->id]);
    makeOrder($admin->business_id, ['store_id' => $beta->id]);

    // The old param must both narrow the list AND come back as the plural
    // one, or the rows would be filtered while the picker read "All stores".
    $this->actingAs($admin)
        ->get(route('orders.index', ['store_id' => $alpha->id]))
        ->assertInertia(fn ($page) => $page
            ->has('orders.data', 1)
            ->where('filters.store_ids', (string) $alpha->id)
            ->etc()
        );
});

test('the products list filters by several stores at once', function () {
    $admin = makeBusinessUser();
    $alpha = makeStore($admin->business_id, ['name' => 'Alpha']);
    $beta = makeStore($admin->business_id, ['name' => 'Beta']);
    $gamma = makeStore($admin->business_id, ['name' => 'Gamma']);

    foreach ([$alpha, $beta, $gamma] as $store) {
        Product::factory()->create([
            'business_id' => $admin->business_id,
            'store_id' => $store->id,
        ]);
    }

    $this->actingAs($admin)
        ->get(route('products.index', ['store_ids' => "{$alpha->id},{$gamma->id}"]))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 2)
            ->where('filters.store_ids', "{$alpha->id},{$gamma->id}")
            ->etc()
        );
});

test('the products list still honours a legacy single store_id link', function () {
    $admin = makeBusinessUser();
    $alpha = makeStore($admin->business_id, ['name' => 'Alpha']);
    $beta = makeStore($admin->business_id, ['name' => 'Beta']);

    foreach ([$alpha, $beta] as $store) {
        Product::factory()->create([
            'business_id' => $admin->business_id,
            'store_id' => $store->id,
        ]);
    }

    $this->actingAs($admin)
        ->get(route('products.index', ['store_id' => $beta->id]))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('filters.store_ids', (string) $beta->id)
            ->etc()
        );
});

test('the parcels list filters by several couriers at once', function () {
    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);

    $accounts = collect(['One', 'Two', 'Three'])->map(fn (string $label) => DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => $label,
        'api_credentials' => 'token',
        'status' => 'active',
    ]));

    foreach ($accounts as $index => $account) {
        makeOrder($admin->business_id, [
            'delivery_account_id' => $account->id,
            'courier_tracking_number' => "TRK-{$index}",
            'delivery_status' => 'in_transit',
            'is_delivery_active' => true,
        ]);
    }

    $ids = $accounts->take(2)->pluck('id')->implode(',');

    $this->actingAs($admin)
        ->get(route('parcels.index', ['delivery_account_ids' => $ids]))
        ->assertInertia(fn ($page) => $page
            ->has('parcels.data', 2)
            ->where('filters.delivery_account_ids', $ids)
            ->etc()
        );
});

test('the parcels list still honours a legacy single delivery_account_id link', function () {
    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);

    $accounts = collect(['One', 'Two'])->map(fn (string $label) => DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => $label,
        'api_credentials' => 'token',
        'status' => 'active',
    ]));

    foreach ($accounts as $index => $account) {
        makeOrder($admin->business_id, [
            'delivery_account_id' => $account->id,
            'courier_tracking_number' => "TRK-{$index}",
            'delivery_status' => 'in_transit',
            'is_delivery_active' => true,
        ]);
    }

    $first = $accounts->first();

    $this->actingAs($admin)
        ->get(route('parcels.index', ['delivery_account_id' => $first->id]))
        ->assertInertia(fn ($page) => $page
            ->has('parcels.data', 1)
            ->where('filters.delivery_account_ids', (string) $first->id)
            ->etc()
        );
});

test('a junk id list degrades to no filter rather than matching nothing', function () {
    $admin = makeBusinessUser();
    $alpha = makeStore($admin->business_id, ['name' => 'Alpha']);

    makeOrder($admin->business_id, ['store_id' => $alpha->id]);
    makeOrder($admin->business_id, ['store_id' => $alpha->id]);

    // Garbage in the param must not silently empty the table — an operator
    // seeing zero orders would read it as "no orders", not "bad link".
    $this->actingAs($admin)
        ->get(route('orders.index', ['store_ids' => 'abc,,0,-4']))
        ->assertInertia(fn ($page) => $page
            ->has('orders.data', 2)
            ->where('filters.store_ids', null)
            ->etc()
        );
});
