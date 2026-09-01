<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The report store/courier filters are multi-select: they take a list of
 * names, and an empty list means "every store"/"every courier". They filter
 * by name rather than id because the report picker is populated from
 * distinct names, not from the store/account rows themselves.
 */
test('the revenue report filters by several stores at once', function () {
    $admin = makeBusinessUser();
    $alpha = makeStore($admin->business_id, ['name' => 'Alpha']);
    $beta = makeStore($admin->business_id, ['name' => 'Beta']);
    $gamma = makeStore($admin->business_id, ['name' => 'Gamma']);

    foreach ([$alpha, $beta, $gamma] as $store) {
        makeOrder($admin->business_id, [
            'store_id' => $store->id,
            'ordered_at' => now()->subDay(),
        ]);
    }

    $response = $this->actingAs($admin)->postJson(route('reports.generate'), [
        'report_id' => 'revenue',
        'store' => ['Alpha', 'Gamma'],
    ]);

    $response->assertOk();
    expect($response->json('rows'))->toHaveCount(2);
    expect(collect($response->json('rows'))->pluck('store')->sort()->values()->all())
        ->toBe(['Alpha', 'Gamma']);
});

test('an omitted store filter leaves the revenue report unfiltered', function () {
    $admin = makeBusinessUser();

    foreach (['Alpha', 'Beta'] as $name) {
        $store = makeStore($admin->business_id, ['name' => $name]);
        makeOrder($admin->business_id, [
            'store_id' => $store->id,
            'ordered_at' => now()->subDay(),
        ]);
    }

    // No 'store' key at all is the "All stores" case — it must return
    // everything, not nothing.
    $response = $this->actingAs($admin)->postJson(route('reports.generate'), [
        'report_id' => 'revenue',
    ]);

    $response->assertOk();
    expect($response->json('rows'))->toHaveCount(2);
});

test('the store-sales report filters by several stores at once', function () {
    $admin = makeBusinessUser();

    foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
        makeStore($admin->business_id, ['name' => $name]);
    }

    $response = $this->actingAs($admin)->postJson(route('reports.generate'), [
        'report_id' => 'store-sales',
        'store' => ['Alpha', 'Beta'],
    ]);

    $response->assertOk();
    expect(collect($response->json('rows'))->pluck('store')->sort()->values()->all())
        ->toBe(['Alpha', 'Beta']);
});

test('a store filter sent as a bare string is rejected', function () {
    $admin = makeBusinessUser();

    // The old contract sent a single name string. Accepting it silently
    // would filter by nothing at all, so it must fail loudly instead.
    $this->actingAs($admin)
        ->postJson(route('reports.generate'), [
            'report_id' => 'revenue',
            'store' => 'Alpha',
        ])
        ->assertStatus(422);
});
