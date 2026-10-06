<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\UserRole;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$payload = [
    'customer_name' => 'Jane Doe',
    'customer_phone' => '+212600000000',
    'customer_address' => '123 Main St',
    'total_amount' => 100,
];

test('an order created by a confirmation agent is assigned to that agent', function () use ($payload) {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);
    // A second idle agent the load-based auto-assign would otherwise
    // pick (fewest orders today, lower id wins ties).
    makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($agent)->post(route('orders.store'), $payload)->assertRedirect();

    $order = Order::where('business_id', $admin->business_id)->firstOrFail();

    expect($order->assigned_agent_id)->toBe($agent->id)
        ->and($order->confirmation_status)->toBe(OrderConfirmationStatus::ASSIGNED);

    // One assignment, logged under the agent, not a reassignment on top
    // of an auto-assign.
    expect($order->statusEvents()->count())->toBe(1)
        ->and($order->statusEvents()->first()->changed_by_user_id)->toBe($agent->id);
});

test('an order created by an admin still goes through auto-assignment', function () use ($payload) {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($admin)->post(route('orders.store'), $payload)->assertRedirect();

    expect(Order::where('business_id', $admin->business_id)->firstOrFail()->assigned_agent_id)
        ->toBe($agent->id);
});
