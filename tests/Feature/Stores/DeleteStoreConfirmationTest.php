<?php

use App\Enums\UserRole;
use App\Models\Scopes\BusinessScope;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('deleting a store requires the typed confirmation', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id, ['name' => 'Acme Shop']);

    $this->actingAs($admin)
        ->from(route('stores.index'))
        ->delete(route('stores.destroy', $store))
        ->assertSessionHasErrors('confirmation');

    expect(Store::find($store->id))->not->toBeNull();
});

test('a mismatched confirmation does not delete the store', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id, ['name' => 'Acme Shop']);

    $this->actingAs($admin)
        ->from(route('stores.index'))
        ->delete(route('stores.destroy', $store), ['confirmation' => 'acme shop'])
        ->assertSessionHasErrors('confirmation');

    expect(Store::find($store->id))->not->toBeNull();
});

test('the exact store name deletes the store', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id, ['name' => 'Acme Shop']);

    $this->actingAs($admin)
        ->delete(route('stores.destroy', $store), ['confirmation' => 'Acme Shop'])
        ->assertRedirect(route('stores.index'));

    expect(Store::find($store->id))->toBeNull();
});

test('another business cannot delete a store even with the right name', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $intruder = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id, ['name' => 'Acme Shop']);

    // 404, not 403: Store is scoped, so binding never resolves it.
    $this->actingAs($intruder)
        ->delete(route('stores.destroy', $store), ['confirmation' => 'Acme Shop'])
        ->assertNotFound();

    // Read without the scope: as the intruder, a scoped find() returns null
    // whether or not the row survived, which would pass either way.
    expect(
        Store::withoutGlobalScope(BusinessScope::class)->find($store->id)
    )->not->toBeNull();
});
