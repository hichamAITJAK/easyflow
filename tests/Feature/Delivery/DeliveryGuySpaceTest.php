<?php

use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\DeliveryCashSettlement;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function spaceGuy(int $businessId, string $city = 'Agadir'): User
{
    $guy = makeBusinessUser(['role' => UserRole::DELIVERY_GUY, 'business_id' => $businessId]);
    $guy->forceFill(['delivery_city' => $city])->save();
    CommissionRule::factory()->create(['business_id' => $businessId, 'user_id' => $guy->id, 'trigger_status' => 'delivered', 'amount' => 15]);

    return $guy;
}

function localParcel(User $guy, string $tracking, OrderDeliveryStatus $status, array $extra = []): Order
{
    return makeOrder($guy->business_id, [
        'delivery_guy_id' => $guy->id,
        'courier_tracking_number' => $tracking,
        'delivery_status' => $status,
        'is_delivery_active' => true,
        'total_amount' => 199,
        ...$extra,
    ]);
}

test('the scan shows the parcel and only the actions its status allows', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = spaceGuy($admin->business_id);
    $other = spaceGuy($admin->business_id, 'Rabat');

    localParcel($guy, 'LOC-AGA-1', OrderDeliveryStatus::AWAITING_PICKUP);
    localParcel($guy, 'LOC-AGA-2', OrderDeliveryStatus::READY_FOR_PICKUP);
    localParcel($guy, 'LOC-AGA-3', OrderDeliveryStatus::OUT_FOR_DELIVERY);
    localParcel($other, 'LOC-RAB-1', OrderDeliveryStatus::READY_FOR_PICKUP);

    $scan = fn (string $code) => $this->actingAs($guy)->postJson(route('delivery.scan'), ['qr_value' => $code]);

    $scan('LOC-AGA-1')->assertOk()->assertJsonPath('actions', []);
    $scan('LOC-AGA-2')->assertOk()->assertJsonPath('actions', ['out_for_delivery'])->assertJsonPath('order.total_amount', '199.00');
    $scan('LOC-AGA-3')->assertOk()->assertJsonPath('actions', ['delivered', 'delivery_attempt_failed', 'postponed', 'refused', 'returned_in_transit']);
    // Not his: reads as not found, never leaks.
    $scan('LOC-RAB-1')->assertUnprocessable();

    $this->actingAs($guy)->get(route('delivery.index'))
        ->assertInertia(fn ($page) => $page->component('delivery/index')
            ->where('summary.to_collect', 1)->where('summary.on_road', 1)->where('summary.city', 'Agadir')->etc());
});

test('taking, failing, postponing and delivering a parcel, with the reason kept and the guy paid once', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = spaceGuy($admin->business_id);
    $parcel = localParcel($guy, 'LOC-AGA-9', OrderDeliveryStatus::READY_FOR_PICKUP);
    $apply = fn (array $body) => $this->actingAs($guy)->postJson(route('delivery.apply'), ['order_id' => $parcel->id, ...$body]);

    // Can't deliver what he has not taken.
    $apply(['action' => 'delivered'])->assertUnprocessable();

    $apply(['action' => 'out_for_delivery'])->assertOk()->assertJsonPath('order.delivery_status', 'out_for_delivery');

    $apply(['action' => 'delivery_attempt_failed'])->assertUnprocessable(); // needs a reason
    $apply(['action' => 'delivery_attempt_failed', 'reason' => 'no_answer', 'note' => 'rang twice'])->assertOk();
    expect(OrderStatusEvent::where('order_id', $parcel->id)->latest('id')->value('note'))->toBe('No answer · rang twice');

    $apply(['action' => 'postponed', 'postponed_until' => now()->addDay()->toDateString()])->assertOk()
        ->assertJsonPath('order.delivery_status', 'postponed');

    $apply(['action' => 'delivered'])->assertOk()
        ->assertJsonPath('summary.delivered_today', 1)
        ->assertJsonPath('summary.cash_owed', 199);

    expect($parcel->fresh()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED)
        ->and((float) CommissionLedgerEntry::withoutGlobalScopes()->where('user_id', $guy->id)->where('entry_type', 'earned')->sum('amount'))->toBe(15.0);

    // The day's log, newest first; a delivery can't be undone with a tap.
    $this->actingAs($guy)->getJson(route('delivery.activity'))
        ->assertOk()
        ->assertJsonPath('events.0.to_status', 'delivered')
        ->assertJsonPath('events.0.can_undo', false)
        ->assertJsonPath('events.2.note', 'No answer · rang twice');
});

test('a return needs a reason and an undo puts the parcel back where it was', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = spaceGuy($admin->business_id);
    $parcel = localParcel($guy, 'LOC-AGA-5', OrderDeliveryStatus::OUT_FOR_DELIVERY);

    $this->actingAs($guy)->postJson(route('delivery.apply'), ['order_id' => $parcel->id, 'action' => 'returned_in_transit'])->assertUnprocessable();
    $this->actingAs($guy)->postJson(route('delivery.apply'), ['order_id' => $parcel->id, 'action' => 'returned_in_transit', 'reason' => 'client_refused'])->assertOk();

    expect($parcel->fresh()->delivery_status)->toBe(OrderDeliveryStatus::RETURNED_IN_TRANSIT)
        ->and($parcel->fresh()->return_reason_code?->value)->toBe('client_refused');

    $eventId = $this->actingAs($guy)->getJson(route('delivery.activity'))->json('events.0.id');
    $this->actingAs($guy)->postJson(route('delivery.undo'), ['event_id' => $eventId])->assertOk();

    expect($parcel->fresh()->delivery_status)->toBe(OrderDeliveryStatus::OUT_FOR_DELIVERY);
    $this->actingAs($guy)->getJson(route('delivery.activity'))->assertJsonCount(0, 'events');
});

test('the admin settles the cash a delivery guy is holding', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = spaceGuy($admin->business_id);
    $a = localParcel($guy, 'LOC-AGA-10', OrderDeliveryStatus::DELIVERED, ['total_amount' => 100, 'is_delivery_active' => false]);
    $b = localParcel($guy, 'LOC-AGA-11', OrderDeliveryStatus::DELIVERED, ['total_amount' => 250.5, 'is_delivery_active' => false]);
    localParcel($guy, 'LOC-AGA-12', OrderDeliveryStatus::OUT_FOR_DELIVERY);

    $this->actingAs($guy)->getJson(route('delivery.cash'))
        ->assertOk()->assertJsonPath('owed', 350.5)->assertJsonCount(2, 'parcels')->assertJsonPath('last_settlement', null);

    $this->actingAs($admin)->post(route('users.cash-settlements.store', $guy), ['note' => 'handed in cash'])->assertRedirect();

    $settlement = DeliveryCashSettlement::withoutGlobalScopes()->firstOrFail();
    expect((float) $settlement->amount)->toBe(350.5)
        ->and($settlement->parcels_count)->toBe(2)
        ->and($a->fresh()->delivery_cash_settlement_id)->toBe($settlement->id)
        ->and($b->fresh()->delivery_cash_settlement_id)->toBe($settlement->id);

    $this->actingAs($guy)->getJson(route('delivery.cash'))
        ->assertJsonPath('owed', 0)->assertJsonPath('last_settlement.parcels_count', 2);

    // The team page shows what he is holding and his open work.
    $this->actingAs($admin)->get(route('users.index'))
        ->assertInertia(fn ($page) => $page->where("workload.{$guy->id}.onRoad", 1)->where("workload.{$guy->id}.cashOwed", 0)->etc());

    // Only an admin; only a delivery guy.
    $this->actingAs($guy)->post(route('users.cash-settlements.store', $guy))->assertForbidden();
    $this->actingAs($admin)->post(route('users.cash-settlements.store', $admin))->assertNotFound();
});
