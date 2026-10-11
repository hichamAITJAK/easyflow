<?php

use App\Enums\CommissionAmountType;
use App\Enums\CommissionPaymentMode;
use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Events\Order\OrderDelivered;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function perParcelAgent(float $amount = 4): User
{
    $agent = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);

    CommissionRule::factory()->create([
        'business_id' => $agent->business_id,
        'user_id' => $agent->id,
        'payment_mode' => CommissionPaymentMode::COMMISSION,
        'trigger_status' => 'ready_for_pickup',
        'amount_type' => CommissionAmountType::FIXED,
        'amount' => $amount,
    ]);

    return $agent;
}

test('a fulfilment agent earns the per-parcel commission on a ready-for-pickup scan, once', function () {
    $agent = perParcelAgent(4);
    $order = makeOrder($agent->business_id, [
        'courier_tracking_number' => 'TRK-COM-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $this->actingAs($agent)->postJson(route('fulfillment.confirm'), ['order_id' => $order->id])->assertOk();

    $entries = CommissionLedgerEntry::withoutGlobalScopes()->where('user_id', $agent->id)->get();
    expect($entries)->toHaveCount(1)
        ->and((float) $entries->first()->amount)->toBe(4.0)
        ->and($entries->first()->order_id)->toBe($order->id);

    // Undo, then scan again: a reversal row and a fresh earned row, never a duplicate.
    $eventId = $this->actingAs($agent)->getJson(route('fulfillment.activity'))->json('events.0.id');
    $this->actingAs($agent)->postJson(route('fulfillment.undo'), ['event_id' => $eventId])->assertOk();

    $reversal = CommissionLedgerEntry::withoutGlobalScopes()->where('entry_type', 'reversal')->first();
    expect($reversal)->not->toBeNull()
        ->and((float) $reversal->amount)->toBe(-4.0)
        ->and($reversal->reversed_entry_id)->toBe($entries->first()->id);

    $this->actingAs($agent)->postJson(route('fulfillment.confirm'), ['order_id' => $order->id])->assertOk();

    expect(CommissionLedgerEntry::withoutGlobalScopes()->where('user_id', $agent->id)->where('entry_type', 'earned')->count())->toBe(2)
        ->and((float) CommissionLedgerEntry::withoutGlobalScopes()->where('user_id', $agent->id)->sum('amount'))->toBe(4.0);

    // And the entries show up on their Commissions page.
    $this->actingAs($agent)->get(route('commission-entries.index'))
        ->assertInertia(fn ($page) => $page
            ->where('isAdmin', false)
            ->has('entries.data', 3)
            ->etc());
});

test('a salaried fulfilment agent and a test order earn nothing', function () {
    $agent = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);
    CommissionRule::factory()->salary()->create(['business_id' => $agent->business_id, 'user_id' => $agent->id]);
    $order = makeOrder($agent->business_id, ['courier_tracking_number' => 'TRK-COM-2', 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP]);
    $this->actingAs($agent)->postJson(route('fulfillment.confirm'), ['order_id' => $order->id])->assertOk();

    $paid = perParcelAgent();
    $test = makeOrder($paid->business_id, ['courier_tracking_number' => 'TRK-COM-3', 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP, 'is_test' => true]);
    $this->actingAs($paid)->postJson(route('fulfillment.confirm'), ['order_id' => $test->id])->assertOk();

    expect(CommissionLedgerEntry::withoutGlobalScopes()->count())->toBe(0);
});

test('a fulfilment agent paid on delivery earns nothing at the scan and is paid once delivered', function () {
    $agent = perParcelAgent(6);
    CommissionRule::withoutGlobalScopes()->where('user_id', $agent->id)->update(['trigger_status' => 'delivered']);
    $order = makeOrder($agent->business_id, [
        'courier_tracking_number' => 'TRK-COM-DLV',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $this->actingAs($agent)->postJson(route('fulfillment.confirm'), ['order_id' => $order->id])->assertOk();
    expect(CommissionLedgerEntry::withoutGlobalScopes()->count())->toBe(0);

    // The courier picks it up and delivers it: no actor on these updates.
    $orders = app(OrderService::class);
    $orders->updateDeliveryStatus($order->fresh(), OrderDeliveryStatus::IN_TRANSIT);
    $orders->updateDeliveryStatus($order->fresh(), OrderDeliveryStatus::DELIVERED);

    $entries = CommissionLedgerEntry::withoutGlobalScopes()->where('user_id', $agent->id)->get();
    expect($entries)->toHaveCount(1)
        ->and((float) $entries->first()->amount)->toBe(6.0)
        ->and($entries->first()->description)->toBe('Parcel delivered');
});

test('the fulfilment form saves the shipped or delivered trigger', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Packer',
        'email' => 'packer@example.com',
        'phone' => '0612345678',
        'role' => 'fulfilment_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'commission',
        'amount' => 5,
        'trigger_status' => 'delivered',
    ])->assertSessionHasNoErrors();

    $agent = User::where('email', 'packer@example.com')->firstOrFail();
    expect(CommissionRule::withoutGlobalScopes()->where('user_id', $agent->id)->value('trigger_status'))->toBe('delivered');
});

test('a confirmation agent paid on delivery earns when the order is delivered, once', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);
    CommissionRule::factory()->onDelivery()->create([
        'business_id' => $agent->business_id,
        'user_id' => $agent->id,
        'amount_type' => CommissionAmountType::FIXED,
        'amount' => 20,
    ]);
    $order = makeOrder($agent->business_id, [
        'assigned_agent_id' => $agent->id,
        'confirmation_status' => 'submitted_to_courier',
        'delivery_status' => OrderDeliveryStatus::IN_TRANSIT,
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'unit_price' => 100, 'quantity' => 1]);

    app(OrderService::class)->updateDeliveryStatus($order, OrderDeliveryStatus::DELIVERED);
    event(new OrderDelivered($order->fresh()));

    $entries = CommissionLedgerEntry::withoutGlobalScopes()->where('user_id', $agent->id)->get();
    expect($entries)->toHaveCount(1)
        ->and((float) $entries->first()->amount)->toBe(20.0);
});
