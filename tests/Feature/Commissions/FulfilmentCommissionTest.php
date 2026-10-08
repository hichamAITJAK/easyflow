<?php

use App\Enums\CommissionAmountType;
use App\Enums\CommissionPaymentMode;
use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\User;
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
