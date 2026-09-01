<?php

use App\Enums\OrderConfirmationStatus;
use App\Events\Order\OrderConfirmed;
use App\Listeners\Order\CalculateAgentCommission;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\OrderItem;
use App\Models\Scopes\BusinessScope;
use App\Models\User;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create an assigned order with one line item and a default commission rule
 * for its agent, ready to be confirmed.
 */
function makeCommissionableOrder(array $orderOverrides = [], array $ruleOverrides = []): array
{
    $admin = makeBusinessUser();
    $agent = User::factory()->create(['business_id' => $admin->business_id]);

    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'assigned',
        'assigned_agent_id' => $agent->id,
        ...$orderOverrides,
    ]);

    OrderItem::factory()->create([
        'business_id' => $admin->business_id,
        'order_id' => $order->id,
        'quantity' => 2,
        'unit_price' => 50,
    ]);

    CommissionRule::factory()->create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'amount' => 10,
        ...$ruleOverrides,
    ]);

    return [$admin, $agent, $order];
}

function earnedEntriesFor(int $orderId): int
{
    return CommissionLedgerEntry::withoutGlobalScope(BusinessScope::class)
        ->where('order_id', $orderId)
        ->where('entry_type', 'earned')
        ->count();
}

test('confirming an order records one earned entry per line item', function () {
    [$admin, , $order] = makeCommissionableOrder();

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect(earnedEntriesFor($order->id))->toBe(1);
});

test('re-confirming an order does not calculate the commission twice', function () {
    [$admin, , $order] = makeCommissionableOrder();
    $service = app(OrderService::class);

    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);
    // An agent can bounce an order back out of CONFIRMED and confirm it
    // again — OrderConfirmed fires a second time for the same order.
    $service->updateStatus($order, $admin, OrderConfirmationStatus::NO_ANSWER);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect(earnedEntriesFor($order->id))->toBe(1);
});

test('handling the same OrderConfirmed event twice records only one entry', function () {
    [, , $order] = makeCommissionableOrder();

    $listener = new CalculateAgentCommission;
    $listener->handle(new OrderConfirmed($order));
    $listener->handle(new OrderConfirmed($order));

    expect(earnedEntriesFor($order->id))->toBe(1);
});

test('the guard holds when no user is authenticated', function () {
    [, , $order] = makeCommissionableOrder();

    $listener = new CalculateAgentCommission;
    $listener->handle(new OrderConfirmed($order));

    // BusinessScope keys off Auth::user(); a queued or console-run listener
    // has none, and must still see the entries written earlier.
    auth()->logout();
    $listener->handle(new OrderConfirmed($order));

    expect(earnedEntriesFor($order->id))->toBe(1);
});

test('a second order for the same agent still earns its own entry', function () {
    [$admin, $agent, $order] = makeCommissionableOrder();
    $service = app(OrderService::class);

    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    $second = makeOrder($admin->business_id, [
        'confirmation_status' => 'assigned',
        'assigned_agent_id' => $agent->id,
    ]);
    OrderItem::factory()->create([
        'business_id' => $admin->business_id,
        'order_id' => $second->id,
        'quantity' => 1,
        'unit_price' => 30,
    ]);

    $service->updateStatus($second, $admin, OrderConfirmationStatus::CONFIRMED);

    expect(earnedEntriesFor($order->id))->toBe(1)
        ->and(earnedEntriesFor($second->id))->toBe(1);
});
