<?php

use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Events\Order\OrderCreated;
use App\Models\DailyStatsReason;
use App\Models\DailyStatsSummary;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('assigning an order increments assigned_count', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'new']);

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::ASSIGNED);

    $row = DailyStatsSummary::where('business_id', $admin->business_id)
        ->whereNull('store_id')->whereNull('agent_id')
        ->first();

    expect($row->assigned_count)->toBe(1);
});

test('submitting an order to courier increments submitted_to_courier_count', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::SUBMITTED_TO_COURIER);

    $row = DailyStatsSummary::where('business_id', $admin->business_id)
        ->whereNull('store_id')->whereNull('agent_id')
        ->first();

    expect($row->submitted_to_courier_count)->toBe(1);
});

test('a refused delivery status increments refused_count', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'submitted_to_courier']);

    app(OrderService::class)->updateDeliveryStatus($order, OrderDeliveryStatus::REFUSED);

    $row = DailyStatsSummary::where('business_id', $admin->business_id)
        ->whereNull('store_id')->whereNull('agent_id')
        ->first();

    expect($row->refused_count)->toBe(1);
});

test('cancelling an order records the reason code in daily_stats_reasons', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'new']);

    app(OrderService::class)->updateStatus(
        $order,
        $admin,
        OrderConfirmationStatus::CANCELLED,
        OrderCancelReason::OUT_OF_STOCK,
    );

    $row = DailyStatsReason::where('business_id', $admin->business_id)
        ->where('reason_type', 'cancel')
        ->where('reason_code', OrderCancelReason::OUT_OF_STOCK->value)
        ->whereNull('store_id')->whereNull('agent_id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->count)->toBe(1);
});

test('a return-in-transit records the reason code in daily_stats_reasons', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'submitted_to_courier']);

    app(OrderService::class)->updateDeliveryStatus(
        $order,
        OrderDeliveryStatus::RETURNED_IN_TRANSIT,
        null,
        OrderReturnReason::WRONG_ADDRESS,
    );

    $row = DailyStatsReason::where('business_id', $admin->business_id)
        ->where('reason_type', 'return')
        ->where('reason_code', OrderReturnReason::WRONG_ADDRESS->value)
        ->whereNull('store_id')->whereNull('agent_id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->count)->toBe(1);
});

test('daily_stats_reasons is scoped per store and agent, same as daily_stats_summary', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'new',
        'assigned_agent_id' => $agent->id,
    ]);

    app(OrderService::class)->updateStatus(
        $order,
        $admin,
        OrderConfirmationStatus::CANCELLED,
        OrderCancelReason::AGENT_ERROR,
    );

    $businessWide = DailyStatsReason::where('business_id', $admin->business_id)
        ->whereNull('store_id')->whereNull('agent_id')
        ->first();
    $agentScoped = DailyStatsReason::where('business_id', $admin->business_id)
        ->where('agent_id', $agent->id)
        ->first();

    expect($businessWide->count)->toBe(1);
    expect($agentScoped->count)->toBe(1);
});

test('a test order does not increment any daily_stats_reasons row', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'new',
        'is_test' => true,
    ]);

    app(OrderService::class)->updateStatus(
        $order,
        $admin,
        OrderConfirmationStatus::CANCELLED,
        OrderCancelReason::OTHER,
    );

    expect(DailyStatsReason::where('business_id', $admin->business_id)->exists())->toBeFalse();
});

test('confirmation_rate is kept in sync as orders_count and confirmed_count change', function () {
    $admin = makeBusinessUser();

    $order1 = makeOrder($admin->business_id, ['confirmation_status' => 'new']);
    app('events')->dispatch(new OrderCreated($order1));
    $order2 = makeOrder($admin->business_id, ['confirmation_status' => 'new']);
    app('events')->dispatch(new OrderCreated($order2));

    $row = DailyStatsSummary::where('business_id', $admin->business_id)
        ->whereNull('store_id')->whereNull('agent_id')->first();
    expect($row->orders_count)->toBe(2);
    expect((float) $row->confirmation_rate)->toBe(0.0);

    app(OrderService::class)->updateStatus($order1, $admin, OrderConfirmationStatus::CONFIRMED);

    $row->refresh();
    expect($row->confirmed_count)->toBe(1);
    expect((float) $row->confirmation_rate)->toBe(50.0);
});

test('delivery_success_rate is kept in sync as submitted_to_courier_count and delivered_count change', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::SUBMITTED_TO_COURIER);

    $row = DailyStatsSummary::where('business_id', $admin->business_id)
        ->whereNull('store_id')->whereNull('agent_id')->first();
    expect($row->submitted_to_courier_count)->toBe(1);
    expect((float) $row->delivery_success_rate)->toBe(0.0);

    app(OrderService::class)->updateDeliveryStatus($order, OrderDeliveryStatus::DELIVERED);

    $row->refresh();
    expect($row->delivered_count)->toBe(1);
    expect((float) $row->delivery_success_rate)->toBe(100.0);
});

test('a store-scoped stats row snapshots the store name as it is created', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id, ['name' => 'Acme Shop']);
    $order = makeOrder($admin->business_id, [
        'store_id' => $store->id,
        'confirmation_status' => 'new',
    ]);

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::ASSIGNED);

    $row = DailyStatsSummary::where('business_id', $admin->business_id)
        ->where('store_id', $store->id)
        ->first();

    // Snapshotted at write time because the stats table has no foreign key
    // to `stores` — without this the dashboard loses the row's identity the
    // moment the store is deleted.
    expect($row)->not->toBeNull()
        ->and($row->store_name)->toBe('Acme Shop');
});
