<?php

use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fulfilmentAgentScopedTo(int $businessId, int ...$storeIds): User
{
    $agent = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT, 'business_id' => $businessId]);

    foreach ($storeIds as $storeId) {
        AgentScope::create(['business_id' => $businessId, 'user_id' => $agent->id, 'store_id' => $storeId]);
    }

    return $agent;
}

test('admin attaches a fulfilment agent to stores and the grants round-trip', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);
    $other = makeStore($admin->business_id);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Warehouse',
        'email' => 'warehouse@example.com',
        'role' => 'fulfilment_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'commission',
        'amount' => 5,
        'store_ids' => [$store->id, $other->id],
    ])->assertSessionHasNoErrors();

    $agent = User::where('email', 'warehouse@example.com')->firstOrFail();
    expect($agent->agentScopes()->pluck('store_id')->sort()->values()->all())
        ->toBe(collect([$store->id, $other->id])->sort()->values()->all());

    // A store from another business is refused.
    $foreign = makeStore(makeBusinessUser()->business_id);
    $this->actingAs($admin)->patch(route('users.update', $agent), [
        'name' => 'Warehouse',
        'email' => 'warehouse@example.com',
        'role' => 'fulfilment_agent',
        'status' => 'active',
        'payment_mode' => 'commission',
        'amount' => 5,
        'store_ids' => [$foreign->id],
    ])->assertSessionHasErrors('store_ids.0');
});

test('a scoped fulfilment agent only sees parcels and products of their stores', function () {
    $admin = makeBusinessUser();
    $mine = makeStore($admin->business_id);
    $theirs = makeStore($admin->business_id);
    $agent = fulfilmentAgentScopedTo($admin->business_id, $mine->id);

    makeOrder($admin->business_id, ['store_id' => $mine->id, 'courier_tracking_number' => 'TRK-MINE']);
    makeOrder($admin->business_id, ['store_id' => $theirs->id, 'courier_tracking_number' => 'TRK-THEIRS']);
    Product::factory()->create(['business_id' => $admin->business_id, 'store_id' => $mine->id, 'name' => 'Mine']);
    Product::factory()->create(['business_id' => $admin->business_id, 'store_id' => $theirs->id, 'name' => 'Theirs']);

    $this->actingAs($agent)->get(route('parcels.index'))
        ->assertInertia(fn ($page) => $page
            ->has('parcels.data', 1)
            ->where('parcels.data.0.courier_tracking_number', 'TRK-MINE')
            ->etc());

    $this->actingAs($agent)->get(route('products.index'))
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Mine')
            ->etc());
});

test('a fulfilment agent can open the history of a parcel in their stores only', function () {
    $admin = makeBusinessUser();
    $mine = makeStore($admin->business_id);
    $theirs = makeStore($admin->business_id);
    $agent = fulfilmentAgentScopedTo($admin->business_id, $mine->id);

    $own = makeOrder($admin->business_id, ['store_id' => $mine->id, 'courier_tracking_number' => 'TRK-1']);
    $foreign = makeOrder($admin->business_id, ['store_id' => $theirs->id, 'courier_tracking_number' => 'TRK-2']);

    $this->actingAs($agent)->getJson(route('orders.show', $own))
        ->assertOk()
        ->assertJsonPath('order.id', $own->id)
        ->assertJsonStructure(['order' => ['status_events']]);

    $this->actingAs($agent)->getJson(route('orders.show', $foreign))->assertForbidden();

    // Unscoped fulfilment agent: the whole business.
    $unscoped = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT, 'business_id' => $admin->business_id]);
    $this->actingAs($unscoped)->getJson(route('orders.show', $foreign))->assertOk();
});

test('the fulfilment scan counts and lookup follow the store grants', function () {
    $admin = makeBusinessUser();
    $mine = makeStore($admin->business_id);
    $theirs = makeStore($admin->business_id);
    $agent = fulfilmentAgentScopedTo($admin->business_id, $mine->id);

    makeOrder($admin->business_id, ['store_id' => $mine->id, 'delivery_status' => 'awaiting_pickup', 'courier_tracking_number' => 'TRK-A']);
    makeOrder($admin->business_id, ['store_id' => $theirs->id, 'delivery_status' => 'awaiting_pickup', 'courier_tracking_number' => 'TRK-B']);

    $this->actingAs($agent)->getJson(route('fulfillment.summary'))
        ->assertOk()
        ->assertJsonPath('ready_to_prepare', 1);

    $this->actingAs($agent)->postJson(route('fulfillment.scan'), ['qr_value' => 'TRK-B'])
        ->assertStatus(422);
});
