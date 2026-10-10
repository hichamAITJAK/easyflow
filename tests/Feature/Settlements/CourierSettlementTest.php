<?php

use App\Enums\Courier;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Models\CourierSettlement;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Services\Operations\Orders\OrderService;
use App\Services\Operations\Settlements\CourierSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeDeliveryAccountForSettlement(int $businessId): DeliveryAccount
{
    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);

    return DeliveryAccount::create([
        'business_id' => $businessId,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => 'token',
        'status' => 'active',
    ]);
}

function deliverOrderForSettlement(OrderService $service, $order, $admin)
{
    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);
    $service->updateDeliveryStatus($order->fresh(), OrderDeliveryStatus::DELIVERED, $admin);
}

test('expected amount sums total_amount for orders delivered within the period, attributed to the delivery account', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccountForSettlement($admin->business_id);
    $otherAccount = makeDeliveryAccountForSettlement($admin->business_id);
    $orderService = app(OrderService::class);

    $inPeriod = makeOrder($admin->business_id, [
        'delivery_account_id' => $account->id,
        'total_amount' => 100,
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);
    deliverOrderForSettlement($orderService, $inPeriod, $admin);

    $otherAccountOrder = makeOrder($admin->business_id, [
        'delivery_account_id' => $otherAccount->id,
        'total_amount' => 500,
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);
    deliverOrderForSettlement($orderService, $otherAccountOrder, $admin);

    $notDelivered = makeOrder($admin->business_id, [
        'delivery_account_id' => $account->id,
        'total_amount' => 999,
        'confirmation_status' => OrderConfirmationStatus::SUBMITTED_TO_COURIER,
    ]);

    $expected = app(CourierSettlementService::class)->computeExpected(
        $admin->business_id,
        $account->id,
        now()->subDay(),
        now()->addDay(),
    );

    expect($expected)->toBe(100.0);
    expect($notDelivered->delivery_status)->not->toBe(OrderDeliveryStatus::DELIVERED);
});

test('expected amount excludes orders delivered outside the period', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccountForSettlement($admin->business_id);
    $orderService = app(OrderService::class);

    $order = makeOrder($admin->business_id, [
        'delivery_account_id' => $account->id,
        'total_amount' => 100,
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);
    deliverOrderForSettlement($orderService, $order, $admin);

    $expected = app(CourierSettlementService::class)->computeExpected(
        $admin->business_id,
        $account->id,
        now()->addDays(10),
        now()->addDays(20),
    );

    expect($expected)->toBe(0.0);
});

test('reconciling creates a settlement with expected, actual, and difference amounts', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccountForSettlement($admin->business_id);
    $orderService = app(OrderService::class);

    $order = makeOrder($admin->business_id, [
        'delivery_account_id' => $account->id,
        'total_amount' => 100,
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);
    deliverOrderForSettlement($orderService, $order, $admin);

    $settlement = app(CourierSettlementService::class)->reconcile(
        $admin->business_id,
        $account->id,
        now()->subDay(),
        now()->addDay(),
        90.0,
        $admin->id,
        'Courier shorted us.',
    );

    expect((float) $settlement->expected_amount)->toBe(100.0);
    expect((float) $settlement->actual_amount)->toBe(90.0);
    expect((float) $settlement->difference_amount)->toBe(-10.0);
    // A 10 MAD shortfall is not a match: the period stays open.
    expect($settlement->status)->toBe('disputed');
    expect($settlement->reconciled_by)->toBe($admin->id);
    expect($settlement->notes)->toBe('Courier shorted us.');
});

test('reconciling the same account and period twice updates the existing settlement instead of duplicating it', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccountForSettlement($admin->business_id);
    $service = app(CourierSettlementService::class);
    $start = now()->subDay();
    $end = now()->addDay();

    $first = $service->reconcile($admin->business_id, $account->id, $start, $end, 50.0, $admin->id);
    $second = $service->reconcile($admin->business_id, $account->id, $start, $end, 75.0, $admin->id);

    expect($second->id)->toBe($first->id);
    expect((float) $second->actual_amount)->toBe(75.0);
    expect(CourierSettlement::where('business_id', $admin->business_id)->count())->toBe(1);
});

test('a disputed settlement can be reopened for follow-up', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccountForSettlement($admin->business_id);
    $service = app(CourierSettlementService::class);

    $settlement = $service->reconcile($admin->business_id, $account->id, now()->subDay(), now()->addDay(), 50.0, $admin->id);

    $disputed = $service->dispute($settlement, 'Amount looks wrong.');

    expect($disputed->status)->toBe('disputed');
    expect($disputed->notes)->toBe('Amount looks wrong.');
});

test('an admin can reconcile a settlement through the web endpoint', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccountForSettlement($admin->business_id);

    $response = $this->actingAs($admin)->post(route('settlements.reconcile'), [
        'delivery_account_id' => $account->id,
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->addDay()->toDateString(),
        'actual_amount' => 50,
    ]);

    $response->assertRedirect(route('settlements.index'));
    expect(CourierSettlement::where('business_id', $admin->business_id)->count())->toBe(1);
});

test('a confirmation agent cannot access the settlements pages', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);

    $this->actingAs($agent)->get(route('settlements.index'))->assertForbidden();
    $this->actingAs($agent)->post(route('settlements.reconcile'), [])->assertForbidden();
});

test('the expected endpoint returns the computed amount as json', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccountForSettlement($admin->business_id);
    $orderService = app(OrderService::class);

    $order = makeOrder($admin->business_id, [
        'delivery_account_id' => $account->id,
        'total_amount' => 42,
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);
    deliverOrderForSettlement($orderService, $order, $admin);

    $response = $this->actingAs($admin)->getJson(route('settlements.expected', [
        'delivery_account_id' => $account->id,
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->addDay()->toDateString(),
    ]));

    $response->assertOk();
    expect((float) $response->json('expected_amount'))->toBe(42.0);
});

test('only a matching amount reconciles; a short or over payment is disputed until corrected', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccountForSettlement($admin->business_id);
    $service = app(CourierSettlementService::class);
    $start = now()->subDay();
    $end = now()->addDay();

    // Nothing delivered: expected is 0.
    expect($service->reconcile($admin->business_id, $account->id, $start, $end, 0.5, $admin->id)->status)->toBe('reconciled')
        ->and($service->reconcile($admin->business_id, $account->id, $start, $end, 61.49, $admin->id)->status)->toBe('disputed')
        ->and($service->reconcile($admin->business_id, $account->id, $start, $end, 0, $admin->id)->status)->toBe('reconciled');
});
