<?php

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function orderWithOneItem(int $businessId, int $agentId): Order
{
    $order = makeOrder($businessId, [
        'assigned_agent_id' => $agentId,
        'confirmation_status' => 'assigned',
    ]);

    OrderItem::create([
        'business_id' => $businessId,
        'order_id' => $order->id,
        'product_name_snapshot' => 'Item',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    return $order;
}

function editPayload(int $quantity, float $unitPrice): array
{
    return [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => $quantity * $unitPrice,
        'items' => [
            ['product_name' => 'Item', 'quantity' => $quantity, 'unit_price' => $unitPrice],
        ],
    ];
}

test('a confirmation agent raising the items total records an upsell', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $order = orderWithOneItem($admin->business_id, $agent->id);

    // 100 → 250
    $this->actingAs($agent)->patch(route('orders.update', $order), editPayload(2, 125))->assertRedirect();
    expect((float) $order->refresh()->upsell_amount)->toBe(150.0);

    // 250 → 200: a later down-sell nets against the earlier upsell.
    $this->actingAs($agent)->patch(route('orders.update', $order), editPayload(2, 100))->assertRedirect();
    expect((float) $order->refresh()->upsell_amount)->toBe(100.0);
});

test('a confirmation agent lowering the items total records a down-sell', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $order = orderWithOneItem($admin->business_id, $agent->id);

    $this->actingAs($agent)->patch(route('orders.update', $order), editPayload(1, 60))->assertRedirect();

    expect((float) $order->refresh()->upsell_amount)->toBe(-40.0);
});

test('an unchanged items total and admin edits leave upsell untouched', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $order = orderWithOneItem($admin->business_id, $agent->id);

    $this->actingAs($agent)->patch(route('orders.update', $order), editPayload(1, 100))->assertRedirect();
    expect($order->refresh()->upsell_amount)->toBeNull();

    $this->actingAs($admin)->patch(route('orders.update', $order), editPayload(3, 100))->assertRedirect();
    expect($order->refresh()->upsell_amount)->toBeNull();
});
