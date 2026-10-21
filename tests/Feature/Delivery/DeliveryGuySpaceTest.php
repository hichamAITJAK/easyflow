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
        'delivery_cost' => 0,
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
    $scan('LOC-AGA-2')->assertOk()->assertJsonPath('actions.0.key', 'ramasse')->assertJsonPath('actions.0.label', 'Ramassé')->assertJsonCount(1, 'actions')->assertJsonPath('order.total_amount', '199.00');
    $scan('LOC-AGA-3')->assertOk()
        ->assertJsonPath('actions.0.key', 'livre')
        ->assertJsonPath('actions.0.status', 'delivered')
        ->assertJsonCount(12, 'actions')
        ->assertJsonPath('actions.9.key', 'reporte')
        ->assertJsonPath('actions.9.needs_date', true);
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
    $apply(['action' => 'livre'])->assertUnprocessable();

    $apply(['action' => 'ramasse'])->assertOk()->assertJsonPath('order.delivery_status', 'out_for_delivery');

    $apply(['action' => 'pas_de_reponse', 'note' => 'rang twice'])->assertOk()->assertJsonPath('order.delivery_status', 'delivery_attempt_failed');
    expect(OrderStatusEvent::where('order_id', $parcel->id)->latest('id')->value('note'))->toBe('Pas de réponse · rang twice');

    // A second failed attempt keeps its own line even though the status stays.
    $apply(['action' => 'deuxieme_appel'])->assertOk();
    expect(OrderStatusEvent::where('order_id', $parcel->id)->where('note', 'Deuxième Appel Pas Réponse')->exists())->toBeTrue();

    $apply(['action' => 'reporte'])->assertUnprocessable(); // needs the date
    $apply(['action' => 'reporte', 'postponed_until' => now()->addDay()->toDateString()])->assertOk()
        ->assertJsonPath('order.delivery_status', 'postponed');

    $apply(['action' => 'livre'])->assertOk()
        ->assertJsonPath('summary.delivered_today', 1)
        // 199 collected minus his 15 MAD pay.
        ->assertJsonPath('summary.cash_owed', 184);

    expect($parcel->fresh()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED)
        ->and((float) CommissionLedgerEntry::withoutGlobalScopes()->where('user_id', $guy->id)->where('entry_type', 'earned')->sum('amount'))->toBe(15.0);

    // The day's log, newest first; a delivery can't be undone with a tap.
    $this->actingAs($guy)->getJson(route('delivery.activity'))
        ->assertOk()
        ->assertJsonPath('events.0.to_status', 'delivered')
        ->assertJsonPath('events.0.can_undo', false)
        ->assertJsonPath('events.3.note', 'Pas de réponse · rang twice');
});

test('a return is marked Retourné and an undo puts the parcel back where it was', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = spaceGuy($admin->business_id);
    $parcel = localParcel($guy, 'LOC-AGA-5', OrderDeliveryStatus::OUT_FOR_DELIVERY);

    $this->actingAs($guy)->postJson(route('delivery.apply'), ['order_id' => $parcel->id, 'action' => 'retourne'])->assertOk();

    expect($parcel->fresh()->delivery_status)->toBe(OrderDeliveryStatus::RETURNED_IN_TRANSIT)
        ->and(OrderStatusEvent::where('order_id', $parcel->id)->latest('id')->value('note'))->toBe('Retourné');

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

test('a delivered parcel carries the delivery guy pay as its delivery cost', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = spaceGuy($admin->business_id);
    $parcel = localParcel($guy, 'LOC-AGA-20', OrderDeliveryStatus::OUT_FOR_DELIVERY, ['delivery_cost' => 0]);

    $this->actingAs($guy)->postJson(route('delivery.apply'), ['order_id' => $parcel->id, 'action' => 'livre'])->assertOk();

    expect((float) $parcel->fresh()->delivery_cost)->toBe(15.0);
});

test('a hand-over records what was received and flags a short one', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = spaceGuy($admin->business_id);
    localParcel($guy, 'LOC-AGA-30', OrderDeliveryStatus::DELIVERED, ['total_amount' => 300, 'is_delivery_active' => false]);

    $this->actingAs($admin)->get(route('settlements.index'))
        ->assertInertia(fn ($page) => $page->where('deliveryGuys.0.owed', 300)->where('deliveryGuys.0.parcels', 1)->etc());

    $this->actingAs($admin)->post(route('users.cash-settlements.store', $guy), ['received_amount' => 250])->assertRedirect();

    $settlement = DeliveryCashSettlement::withoutGlobalScopes()->firstOrFail();
    expect((float) $settlement->amount)->toBe(300.0)
        ->and((float) $settlement->received_amount)->toBe(250.0)
        ->and((float) $settlement->difference_amount)->toBe(-50.0)
        ->and($settlement->status)->toBe('disputed')
        ->and((float) $settlement->balance_after)->toBe(50.0);

    // The missing 50 stays on his balance and shows in his space.
    $this->actingAs($guy)->getJson(route('delivery.cash'))
        ->assertJsonPath('owed', 50)->assertJsonPath('carried', 50)->assertJsonCount(0, 'parcels');
    $this->actingAs($guy)->getJson(route('delivery.summary'))->assertJsonPath('cash_owed', 50);
    $this->actingAs($admin)->get(route('users.index'))
        ->assertInertia(fn ($page) => $page->where("workload.{$guy->id}.cashOwed", 50)->etc());

    // Next hand-over: a new parcel net of pay (100 - 15) plus the 50 left.
    localParcel($guy, 'LOC-AGA-31', OrderDeliveryStatus::DELIVERED, ['total_amount' => 100, 'delivery_cost' => 15, 'is_delivery_active' => false]);
    $this->actingAs($admin)->get(route('settlements.index'))
        ->assertInertia(fn ($page) => $page->where('deliveryGuys.0.owed', 135)->where('deliveryGuys.0.carried', 50)->etc());
    $this->actingAs($admin)->post(route('users.cash-settlements.store', $guy), ['received_amount' => 135])->assertRedirect();
    expect(DeliveryCashSettlement::withoutGlobalScopes()->latest('id')->value('balance_after'))->toBe('0.00');
    $this->actingAs($guy)->getJson(route('delivery.cash'))->assertJsonPath('owed', 0);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('expected.lastSettlement.status', 'matched')
            ->where('expected.toReceiveMad', 0)
            ->etc());
});

test('his parcels list shows every parcel with its state', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = spaceGuy($admin->business_id);
    localParcel($guy, 'LOC-AGA-50', OrderDeliveryStatus::DELIVERED, ['is_delivery_active' => false]);
    localParcel($guy, 'LOC-AGA-51', OrderDeliveryStatus::OUT_FOR_DELIVERY);
    localParcel(spaceGuy($admin->business_id, 'Rabat'), 'LOC-RAB-50', OrderDeliveryStatus::OUT_FOR_DELIVERY);

    $this->actingAs($guy)->getJson(route('delivery.parcels'))
        ->assertOk()
        ->assertJsonCount(2, 'parcels')
        ->assertJsonPath('parcels.0.settled', false);
});

test('the finance row can be scoped to one delivery guy or one courier account', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = spaceGuy($admin->business_id);
    $other = spaceGuy($admin->business_id, 'Rabat');
    localParcel($guy, 'LOC-AGA-40', OrderDeliveryStatus::DELIVERED, ['total_amount' => 120, 'is_delivery_active' => false]);
    localParcel($guy, 'LOC-AGA-41', OrderDeliveryStatus::OUT_FOR_DELIVERY);
    localParcel($other, 'LOC-RAB-40', OrderDeliveryStatus::DELIVERED, ['total_amount' => 80, 'is_delivery_active' => false]);

    $this->actingAs($admin)->get(route('dashboard', ['carrier' => 'guy:'.$guy->id]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.carrier', 'guy:'.$guy->id)
            ->where('expected.toReceiveMad', 120)
            ->where('expected.deliveredUnpaid', 1)
            ->where('parcels.total', 2)
            ->where('carriers.0.kind', 'guy')
            ->etc());

    // Everyone: both guys' cash.
    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('filters.carrier', null)->where('expected.toReceiveMad', 200)->where('parcels.total', 3)->etc());

    // An unknown carrier is ignored, not an empty page.
    $this->actingAs($admin)->get(route('dashboard', ['carrier' => 'guy:999']))
        ->assertInertia(fn ($page) => $page->where('filters.carrier', null)->etc());
});
