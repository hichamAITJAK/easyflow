<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\User;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeDeliveryGuy(int $businessId, string $city = 'Agadir', float $perParcel = 15): User
{
    $guy = makeBusinessUser(['role' => UserRole::DELIVERY_GUY, 'business_id' => $businessId]);
    $guy->forceFill(['delivery_city' => $city])->save();

    CommissionRule::factory()->create([
        'business_id' => $businessId,
        'user_id' => $guy->id,
        'trigger_status' => 'delivered',
        'amount' => $perParcel,
    ]);

    return $guy;
}

test('an admin adds a delivery guy with his city and a per-parcel rate', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Youssef',
        'email' => 'youssef@example.com',
        'role' => 'delivery_guy',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'delivery_city' => 'Agadir',
        'payment_mode' => 'commission',
        'amount' => 15,
    ])->assertSessionHasNoErrors();

    $guy = User::where('email', 'youssef@example.com')->firstOrFail();
    $rule = CommissionRule::where('user_id', $guy->id)->firstOrFail();

    expect($guy->role)->toBe(UserRole::DELIVERY_GUY)
        ->and($guy->delivery_city)->toBe('Agadir')
        ->and($rule->trigger_status)->toBe('delivered')
        ->and($rule->amount_type->value)->toBe('fixed')
        ->and((float) $rule->amount)->toBe(15.0);

    // The city is required.
    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Sans Ville', 'email' => 'sv@example.com', 'role' => 'delivery_guy', 'status' => 'active',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'payment_mode' => 'commission', 'amount' => 10,
    ])->assertSessionHasErrors('delivery_city');
});

test('a confirmer hands a confirmed order to a delivery guy instead of a courier', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = makeDeliveryGuy($admin->business_id, 'Agadir');
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed', 'assigned_agent_id' => $agent->id, 'customer_city' => 'Casablanca']);

    // The form offers the guys with their city.
    $this->actingAs($agent)->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->where('deliveryGuys.0.name', $guy->name)->where('deliveryGuys.0.delivery_city', 'Agadir')->etc());

    $this->actingAs($agent)->post(route('orders.shipment', $order), [
        'delivery_guy_id' => $guy->id,
        'customer_name' => 'Hicham',
        'customer_phone' => '+212646437398',
        'customer_address' => 'Block 8 nr 15',
        'total_amount' => 199,
        'parcel_note' => 'just test',
    ])->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->delivery_guy_id)->toBe($guy->id)
        ->and($order->delivery_account_id)->toBeNull()
        ->and($order->courier_tracking_number)->toStartWith('LOC-AGA-')
        ->and($order->courier_slug)->toBe('own')
        ->and($order->customer_city)->toBe('Agadir')
        ->and($order->confirmation_status)->toBe(OrderConfirmationStatus::SUBMITTED_TO_COURIER)
        ->and($order->delivery_status)->toBe(OrderDeliveryStatus::AWAITING_PICKUP)
        ->and($order->shipped_at)->not->toBeNull();

    // A courier account is still required when no guy is chosen.
    $other = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed', 'assigned_agent_id' => $agent->id]);
    $this->actingAs($agent)->post(route('orders.shipment', $other), [
        'customer_name' => 'X', 'customer_phone' => '+212600000001', 'customer_address' => 'Y', 'total_amount' => 10,
    ])->assertSessionHasErrors(['delivery_account_id', 'city_id']);

    // Another business's delivery guy is not an option.
    $stranger = makeDeliveryGuy(makeBusinessUser()->business_id);
    $this->actingAs($agent)->post(route('orders.shipment', $other), [
        'delivery_guy_id' => $stranger->id,
        'customer_name' => 'X', 'customer_phone' => '+212600000001', 'customer_address' => 'Y', 'total_amount' => 10,
    ])->assertSessionHasErrors('delivery_guy_id');
});

test('a local parcel goes through the warehouse scan and pays the delivery guy once delivered', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = makeDeliveryGuy($admin->business_id, 'Agadir', 15);
    $warehouse = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT, 'business_id' => $admin->business_id]);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    app(OrderService::class)->createShipment($order, $admin, [
        'delivery_guy_id' => $guy->id,
        'customer_name' => 'Hicham', 'customer_phone' => '+212646437398', 'customer_address' => 'Block 8', 'total_amount' => 199,
    ]);
    $order->refresh();

    $scanned = $this->actingAs($warehouse)->postJson(route('fulfillment.scan'), ['qr_value' => $order->courier_tracking_number])
        ->assertOk()->assertJsonPath('action', 'ready_for_pickup')
        ->assertJsonPath('order.courier', 'Own delivery · '.$guy->name);
    $this->actingAs($warehouse)->postJson(route('fulfillment.confirm'), ['order_id' => $scanned->json('order.id')])->assertOk();

    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::READY_FOR_PICKUP);

    $service = app(OrderService::class);
    $service->updateDeliveryStatus($order->fresh(), OrderDeliveryStatus::OUT_FOR_DELIVERY, $guy);
    $service->updateDeliveryStatus($order->fresh(), OrderDeliveryStatus::DELIVERED, $guy);

    $earned = CommissionLedgerEntry::withoutGlobalScopes()->where('user_id', $guy->id)->where('entry_type', 'earned')->get();
    expect($earned)->toHaveCount(1)
        ->and((float) $earned->first()->amount)->toBe(15.0);

    // Nothing for the hourly courier sync to poll: no account on it.
    expect($order->fresh()->delivery_account_id)->toBeNull();
});

test('the delivery guy sees his own parcels and nothing else', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = makeDeliveryGuy($admin->business_id, 'Agadir');
    $otherGuy = makeDeliveryGuy($admin->business_id, 'Rabat');

    makeOrder($admin->business_id, ['delivery_guy_id' => $guy->id, 'courier_tracking_number' => 'LOC-AGA-1', 'delivery_status' => OrderDeliveryStatus::READY_FOR_PICKUP]);
    makeOrder($admin->business_id, ['delivery_guy_id' => $otherGuy->id, 'courier_tracking_number' => 'LOC-RAB-1', 'delivery_status' => OrderDeliveryStatus::READY_FOR_PICKUP]);
    makeOrder($admin->business_id, ['delivery_guy_id' => $guy->id, 'courier_tracking_number' => 'LOC-AGA-2', 'delivery_status' => OrderDeliveryStatus::DELIVERED]);

    $this->actingAs($guy)->get(route('delivery.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('delivery/index')
            ->where('city', 'Agadir')
            ->has('parcels', 1)
            ->where('parcels.0.courier_tracking_number', 'LOC-AGA-1')
            ->where('deliveredToday', 1)
            ->etc());

    // Landing page after login, and no way into the operations app.
    $this->actingAs($guy)->get(route('dashboard'))->assertRedirect(route('delivery.index'));
    $this->actingAs($guy)->get(route('orders.index'))->assertForbidden();

    $warehouse = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT, 'business_id' => $admin->business_id]);
    $this->actingAs($warehouse)->get(route('delivery.index'))->assertForbidden();
});
