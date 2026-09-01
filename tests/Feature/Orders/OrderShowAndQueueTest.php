<?php

use App\Enums\UserRole;
use App\Models\OrderStatusEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an admin can fetch full order detail including status history', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);
    OrderStatusEvent::create([
        'business_id' => $admin->business_id,
        'order_id' => $order->id,
        'from_status' => 'new',
        'to_status' => 'confirmed',
        'changed_by_user_id' => $admin->id,
    ]);

    $response = $this->actingAs($admin)->getJson(route('orders.show', $order));

    $response->assertOk();
    $response->assertJsonPath('order.id', $order->id);
    $response->assertJsonCount(1, 'order.status_events');
    $response->assertJsonPath('order.status_events.0.to_status', 'confirmed');
});

test('a confirmation agent can fetch detail for their own assigned order', function () {
    $admin = makeBusinessUser();
    $agent = User::factory()->create([
        'business_id' => $admin->business_id,
        'role' => UserRole::CONFIRMATION_AGENT,
    ]);
    $order = makeOrder($admin->business_id, ['assigned_agent_id' => $agent->id]);

    $this->actingAs($agent)
        ->getJson(route('orders.show', $order))
        ->assertOk();
});

test('a confirmation agent cannot fetch detail for an order not assigned to them', function () {
    $admin = makeBusinessUser();
    $agent = User::factory()->create([
        'business_id' => $admin->business_id,
        'role' => UserRole::CONFIRMATION_AGENT,
    ]);
    $order = makeOrder($admin->business_id, ['assigned_agent_id' => null]);

    $this->actingAs($agent)
        ->getJson(route('orders.show', $order))
        ->assertForbidden();
});

test('an admin cannot fetch order detail for another business\'s order', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();
    $order = makeOrder($otherAdmin->business_id);

    $this->actingAs($admin)
        ->getJson(route('orders.show', $order))
        ->assertNotFound();
});

test('the bucket filter groups confirmation statuses on the orders index', function () {
    $admin = makeBusinessUser();
    makeOrder($admin->business_id, ['confirmation_status' => 'new']);
    makeOrder($admin->business_id, ['confirmation_status' => 'callback']);
    makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $response = $this->actingAs($admin)->get(route('orders.index', ['bucket' => 'follow_up']));

    $response->assertInertia(fn ($page) => $page
        ->has('orders.data', 1)
        ->where('orders.data.0.confirmation_status', 'callback')
    );
});

test('a confirmation agent visiting the orders route sees the queue component with bucket counts', function () {
    $admin = makeBusinessUser();
    $agent = User::factory()->create([
        'business_id' => $admin->business_id,
        'role' => UserRole::CONFIRMATION_AGENT,
    ]);
    makeOrder($admin->business_id, ['assigned_agent_id' => $agent->id, 'confirmation_status' => 'new']);

    $response = $this->actingAs($agent)->get(route('orders.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('orders/queue')
        ->where('bucketCounts.new', 1)
        ->where('bucketCounts.all', 1)
    );
});

test('the agent queue honours the per_page selector', function () {
    $admin = makeBusinessUser();
    $agent = User::factory()->create([
        'business_id' => $admin->business_id,
        'role' => UserRole::CONFIRMATION_AGENT,
    ]);

    foreach (range(1, 12) as $ignored) {
        makeOrder($admin->business_id, [
            'assigned_agent_id' => $agent->id,
            'confirmation_status' => 'new',
        ]);
    }

    // The queue renders a different component than the admin table but shares
    // the same paginate() call, so the page-size control has to work here too.
    $this->actingAs($agent)
        ->get(route('orders.index', ['per_page' => 10]))
        ->assertInertia(fn ($page) => $page
            ->component('orders/queue')
            ->where('orders.per_page', 10)
            ->where('orders.total', 12)
            ->count('orders.data', 10)
            ->where('filters.per_page', '10')
        );

    // Out-of-whitelist values fall back to the default instead of letting a
    // crafted per_page pull the whole table into one response.
    $this->actingAs($agent)
        ->get(route('orders.index', ['per_page' => 5000]))
        ->assertInertia(fn ($page) => $page->where('orders.per_page', 20));
});

test('orders can be sorted by source', function () {
    $admin = makeBusinessUser();
    makeOrder($admin->business_id, ['source_platform' => 'whatsapp']);
    makeOrder($admin->business_id, ['source_platform' => 'phone_call']);

    // The Source column prints a sort header, so the value has to be in the
    // SORTABLE whitelist or the header silently does nothing.
    $this->actingAs($admin)
        ->get(route('orders.index', ['sort' => 'source_platform', 'direction' => 'asc']))
        ->assertInertia(fn ($page) => $page
            ->component('orders/index')
            ->where('orders.data.0.source_platform', 'phone_call')
            ->where('filters.sort', 'source_platform')
        );
});
