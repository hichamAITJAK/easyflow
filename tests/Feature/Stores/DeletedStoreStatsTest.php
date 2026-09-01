<?php

use App\Enums\UserRole;
use App\Models\DailyStatsSummary;
use App\Models\Scopes\BusinessScope;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a stats row snapshots the store name when it is created', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id, ['name' => 'Acme Shop']);

    $row = DailyStatsSummary::create([
        'business_id' => $admin->business_id,
        'store_id' => $store->id,
        'store_name' => $store->name,
        'stat_date' => '2026-08-01',
        'orders_count' => 3,
    ]);

    expect($row->fresh()->store_name)->toBe('Acme Shop');
});

test('the snapshot survives the store being deleted', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id, ['name' => 'Acme Shop']);

    $row = DailyStatsSummary::create([
        'business_id' => $admin->business_id,
        'store_id' => $store->id,
        'store_name' => $store->name,
        'stat_date' => '2026-08-01',
        'orders_count' => 3,
    ]);

    $this->actingAs($admin)
        ->delete(route('stores.destroy', $store), ['confirmation' => 'Acme Shop']);

    expect(Store::withoutGlobalScope(BusinessScope::class)->find($store->id))->toBeNull();

    // The stats row has no foreign key to stores on purpose, so it outlives
    // the store — and still knows which store it belonged to.
    $survivor = $row->fresh();

    expect($survivor)->not->toBeNull()
        ->and($survivor->store_id)->toBe($store->id)
        ->and($survivor->store_name)->toBe('Acme Shop');
});
