<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\UserRole;
use App\Models\OrderStatusEvent;
use App\Models\User;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAgent(int $businessId): User
{
    return makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $businessId,
    ]);
}

test('assigning a new order moves it to assigned', function () {
    $admin = makeBusinessUser();
    $agent = makeAgent($admin->business_id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'new', 'assigned_agent_id' => null]);

    $this->actingAs($admin)
        ->patch(route('orders.assign', $order), ['assigned_agent_id' => $agent->id])
        ->assertRedirect(route('orders.index'));

    $order->refresh();
    expect($order->assigned_agent_id)->toBe($agent->id);
    expect($order->confirmation_status)->toBe(OrderConfirmationStatus::ASSIGNED);
});

test('unassigning an assigned order reverts it to new', function () {
    $admin = makeBusinessUser();
    $agent = makeAgent($admin->business_id);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'assigned',
        'assigned_agent_id' => $agent->id,
    ]);

    $this->actingAs($admin)
        ->patch(route('orders.assign', $order), ['assigned_agent_id' => null])
        ->assertRedirect(route('orders.index'));

    $order->refresh();
    expect($order->assigned_agent_id)->toBeNull();
    expect($order->confirmation_status)->toBe(OrderConfirmationStatus::NEW);
});

test('reassigning an already-confirmed order changes the agent without touching its status', function () {
    $admin = makeBusinessUser();
    $agentA = makeAgent($admin->business_id);
    $agentB = makeAgent($admin->business_id);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'confirmed',
        'assigned_agent_id' => $agentA->id,
    ]);

    $this->actingAs($admin)
        ->patch(route('orders.assign', $order), ['assigned_agent_id' => $agentB->id])
        ->assertRedirect(route('orders.index'));

    $order->refresh();
    expect($order->assigned_agent_id)->toBe($agentB->id);
    expect($order->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
});

test('unassigning an already-confirmed order does not revert its status to new', function () {
    $admin = makeBusinessUser();
    $agent = makeAgent($admin->business_id);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'confirmed',
        'assigned_agent_id' => $agent->id,
    ]);

    $this->actingAs($admin)
        ->patch(route('orders.assign', $order), ['assigned_agent_id' => null])
        ->assertRedirect(route('orders.index'));

    $order->refresh();
    expect($order->assigned_agent_id)->toBeNull();
    expect($order->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
});

test('assigning an agent to a new order writes an audit event with the acting admin', function () {
    $admin = makeBusinessUser();
    $agent = makeAgent($admin->business_id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'new', 'assigned_agent_id' => null]);

    $this->actingAs($admin)->patch(route('orders.assign', $order), ['assigned_agent_id' => $agent->id]);

    $event = OrderStatusEvent::where('order_id', $order->id)->latest('id')->first();
    expect($event)->not->toBeNull();
    expect($event->from_status)->toBe('new');
    expect($event->to_status)->toBe('assigned');
    expect($event->changed_by_user_id)->toBe($admin->id);
});

test('auto-assigning a newly created order on creation also moves it to assigned', function () {
    $admin = makeBusinessUser();
    makeAgent($admin->business_id);

    $order = app(OrderService::class)->createManualOrder($admin->business_id, [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ]);

    $order->refresh();
    expect($order->assigned_agent_id)->not->toBeNull();
    expect($order->confirmation_status)->toBe(OrderConfirmationStatus::ASSIGNED);
});
