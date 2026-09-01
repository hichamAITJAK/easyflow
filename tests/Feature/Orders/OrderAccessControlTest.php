<?php

use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the store filter options are scoped to a confirmation agent\'s AgentScope stores', function () {
    $admin = makeBusinessUser();
    $storeA = makeStore($admin->business_id, ['name' => 'Store A']);
    $storeB = makeStore($admin->business_id, ['name' => 'Store B']);

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

    $response = $this->actingAs($agent)->get(route('orders.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('orders/queue')
        ->has('stores', 1)
        ->where('stores.0.id', $storeA->id)
    );
});

test('an agent with no AgentScope rows sees every business store in the filter', function () {
    $admin = makeBusinessUser();
    makeStore($admin->business_id, ['name' => 'Store A']);
    makeStore($admin->business_id, ['name' => 'Store B']);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $response = $this->actingAs($agent)->get(route('orders.index'));

    $response->assertInertia(fn ($page) => $page->has('stores', 2));
});

test('an admin sees every business store in the filter regardless of AgentScope', function () {
    $admin = makeBusinessUser();
    makeStore($admin->business_id);
    makeStore($admin->business_id);

    $response = $this->actingAs($admin)->get(route('orders.index'));

    $response->assertInertia(fn ($page) => $page->has('stores', 2));
});

test('a confirmation agent cannot delete an order', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($agent)
        ->delete(route('orders.destroy', $order))
        ->assertForbidden();

    expect(Order::find($order->id))->not->toBeNull();
});

test('a confirmation agent cannot bulk-delete orders', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($agent)
        ->delete(route('orders.bulk-destroy'), ['ids' => [$order->id]])
        ->assertForbidden();

    expect(Order::find($order->id))->not->toBeNull();
});

test('an admin can delete an order', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id);

    $this->actingAs($admin)
        ->delete(route('orders.destroy', $order))
        ->assertRedirect(route('orders.index'));

    expect(Order::find($order->id))->toBeNull();
});

test('a confirmation agent only sees orders assigned to them, not unassigned or other agents\' orders', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);
    $otherAgent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $ownOrder = makeOrder($admin->business_id, ['assigned_agent_id' => $agent->id]);
    makeOrder($admin->business_id, ['assigned_agent_id' => null]);
    makeOrder($admin->business_id, ['assigned_agent_id' => $otherAgent->id]);

    $response = $this->actingAs($agent)->get(route('orders.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('orders.data', 1)
        ->where('orders.data.0.id', $ownOrder->id)
    );
});

test('a fulfilment agent never reaches the web orders list at all', function () {
    $admin = makeBusinessUser();
    makeOrder($admin->business_id, ['assigned_agent_id' => null]);

    $fulfilmentAgent = makeBusinessUser([
        'role' => UserRole::FULFILMENT_AGENT,
        'business_id' => $admin->business_id,
    ]);

    // Mobile-only role: EnsureAccountStillUsable signs them out of the web
    // app rather than rendering an empty list for them.
    $this->actingAs($fulfilmentAgent)
        ->get(route('orders.index'))
        ->assertRedirect(route('account-status', ['reason' => 'mobile-only-role']));
});

test('an admin sees every order regardless of assignment', function () {
    $admin = makeBusinessUser();
    makeOrder($admin->business_id, ['assigned_agent_id' => null]);
    makeOrder($admin->business_id, ['assigned_agent_id' => $admin->id]);

    $response = $this->actingAs($admin)->get(route('orders.index'));

    $response->assertInertia(fn ($page) => $page->has('orders.data', 2));
});

test('a confirmation agent cannot update the status of an order not assigned to them', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);
    $order = makeOrder($admin->business_id, ['assigned_agent_id' => null]);

    $this->actingAs($agent)
        ->patch(route('orders.status', $order), ['confirmation_status' => 'confirmed'])
        ->assertForbidden();

    expect($order->fresh()->confirmation_status->value)->toBe('new');
});

test('a confirmation agent can update the status of their own assigned order', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);
    $order = makeOrder($admin->business_id, ['assigned_agent_id' => $agent->id]);

    $this->actingAs($agent)
        ->patch(route('orders.status', $order), ['confirmation_status' => 'confirmed'])
        ->assertRedirect(route('orders.index'));

    expect($order->fresh()->confirmation_status->value)->toBe('confirmed');
});

test('a confirmation agent cannot assign an order', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);
    $order = makeOrder($admin->business_id, ['assigned_agent_id' => null]);

    $this->actingAs($agent)
        ->patch(route('orders.assign', $order), ['assigned_agent_id' => $agent->id])
        ->assertForbidden();

    expect($order->fresh()->assigned_agent_id)->toBeNull();
});

test('an admin can assign an order', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);
    $order = makeOrder($admin->business_id, ['assigned_agent_id' => null]);

    $this->actingAs($admin)
        ->patch(route('orders.assign', $order), ['assigned_agent_id' => $agent->id])
        ->assertRedirect(route('orders.index'));

    expect($order->fresh()->assigned_agent_id)->toBe($agent->id);
});

test('the orders page counts orders submitted to the courier', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    makeOrder($admin->business_id, ['confirmation_status' => 'submitted_to_courier']);
    makeOrder($admin->business_id, ['confirmation_status' => 'submitted_to_courier']);
    makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $this->actingAs($admin)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page
            ->where('metrics.submitted_to_courier', 2)
            ->where('metrics.confirmed', 1)
            ->etc()
        );
});

test('a user cannot hand-set an order to submitted_to_courier', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    // Only a successful courier API call may set this. Hand-setting it
    // would claim a parcel exists at a courier that never received one —
    // an order that can never be tracked or settled.
    $this->actingAs($admin)
        ->patch(route('orders.status', $order), [
            'confirmation_status' => 'submitted_to_courier',
        ])
        ->assertSessionHasErrors('confirmation_status');

    expect($order->fresh()->confirmation_status->value)->toBe('confirmed');
});

test('the bulk endpoint is not a way around the manual-status restriction', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $this->actingAs($admin)
        ->patch(route('orders.bulk-status'), [
            'ids' => [$order->id],
            'confirmation_status' => 'submitted_to_courier',
        ])
        ->assertSessionHasErrors('confirmation_status');

    expect($order->fresh()->confirmation_status->value)->toBe('confirmed');
});

test('a user can still set a normal confirmation status', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'new']);

    // The restriction must not catch the ordinary transitions agents make
    // all day.
    $this->actingAs($admin)
        ->patch(route('orders.status', $order), [
            'confirmation_status' => 'confirmed',
        ])
        ->assertSessionHasNoErrors();

    expect($order->fresh()->confirmation_status->value)->toBe('confirmed');
});

test('the bulk routes are reachable and not shadowed by the /{order} ones', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);
    $this->actingAs($admin);

    // Route matching follows registration order, so a /{order} route
    // declared first swallows /orders/bulk/... and binds $order to the
    // literal "bulk". That 404 is silent from the UI's side — the bulk
    // dialog just does nothing — so each route is pinned here.
    foreach ([
        ['patch', 'orders.bulk-status', ['ids' => [$order->id], 'confirmation_status' => 'confirmed']],
        ['patch', 'orders.bulk-assign', ['ids' => [$order->id], 'assigned_agent_id' => null]],
        ['delete', 'orders.bulk-destroy', ['ids' => [$order->id]]],
    ] as [$method, $name, $payload]) {
        expect($this->$method(route($name), $payload)->getStatusCode())
            ->not->toBe(404, "{$name} is shadowed by an /{order} route");
    }
});
