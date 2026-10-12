<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
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

test('an agent on salary plus commission still earns the commission', function () {
    [$admin, , $order] = makeCommissionableOrder(ruleOverrides: [
        'payment_mode' => 'salary_and_commission',
        'salary_amount' => 3000,
        'salary_period' => 'monthly',
    ]);

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect(earnedEntriesFor($order->id))->toBe(1);
});

test('an agent on salary only earns no commission', function () {
    [$admin, , $order] = makeCommissionableOrder(ruleOverrides: [
        'payment_mode' => 'salary',
        'salary_amount' => 3000,
        'salary_period' => 'monthly',
    ]);

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect(earnedEntriesFor($order->id))->toBe(0);
});

function upsellEntryFor(int $orderId): ?CommissionLedgerEntry
{
    return CommissionLedgerEntry::withoutGlobalScope(BusinessScope::class)
        ->where('order_id', $orderId)
        ->where('description', 'like', 'Upsell%')
        ->first();
}

test('a fixed upsell commission is paid once per upsold order at confirmation', function () {
    [$admin, , $order] = makeCommissionableOrder(['upsell_amount' => 150], ['upsell_amount_type' => 'fixed', 'upsell_amount' => 20]);
    $service = app(OrderService::class);

    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::NO_ANSWER);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect((float) upsellEntryFor($order->id)->amount)->toBe(20.0)
        ->and(earnedEntriesFor($order->id))->toBe(2);
});

test('a percentage upsell commission is a share of the upsell only', function () {
    [$admin, , $order] = makeCommissionableOrder(['upsell_amount' => 150], ['upsell_amount_type' => 'percentage', 'upsell_amount' => 10]);

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect((float) upsellEntryFor($order->id)->amount)->toBe(15.0);
});

test('no upsell commission without an upsell, or for a down-sell', function () {
    foreach ([null, 0, -40] as $upsell) {
        [$admin, , $order] = makeCommissionableOrder(['upsell_amount' => $upsell], ['upsell_amount_type' => 'fixed', 'upsell_amount' => 20]);

        app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

        expect(upsellEntryFor($order->id))->toBeNull()
            ->and(earnedEntriesFor($order->id))->toBe(1);
    }
});

test('an agent paid on delivery earns the upsell commission on delivery', function () {
    [$admin, , $order] = makeCommissionableOrder(
        ['upsell_amount' => 100],
        ['trigger_status' => 'delivered', 'upsell_amount_type' => 'fixed', 'upsell_amount' => 12],
    );
    $service = app(OrderService::class);

    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);
    expect(upsellEntryFor($order->id))->toBeNull();

    $service->updateDeliveryStatus($order->fresh(), OrderDeliveryStatus::DELIVERED);

    expect((float) upsellEntryFor($order->id)->amount)->toBe(12.0);
});
