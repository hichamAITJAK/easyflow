<?php

use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Models\CourierSettlement;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCashSettlement;
use App\Models\DeliveryCourrier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agents cannot open cash tracking', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    $this->actingAs($agent)->get(route('finance.cash.index'))->assertForbidden();
});

test('cash tracking lists couriers and delivery guys with period figures and the running balance', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $courier = DeliveryCourrier::factory()->create(['name' => 'OzonExpress']);
    $account = DeliveryAccount::factory()->create(['business_id' => $admin->business_id, 'courier_id' => $courier->id, 'label' => 'Casa account']);
    $guy = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::DELIVERY_GUY, 'name' => 'Hamza']);

    // Courier: 3 parcels shipped this week — one delivered (300 − 30),
    // one returned, one still on its way.
    makeOrder($admin->business_id, ['delivery_account_id' => $account->id, 'shipped_at' => now()->subDays(2), 'delivery_status' => OrderDeliveryStatus::DELIVERED, 'total_amount' => 300, 'delivery_cost' => 30]);
    makeOrder($admin->business_id, ['delivery_account_id' => $account->id, 'shipped_at' => now()->subDays(2), 'delivery_status' => OrderDeliveryStatus::RETURN_RECEIVED, 'total_amount' => 150]);
    makeOrder($admin->business_id, ['delivery_account_id' => $account->id, 'shipped_at' => now()->subDay(), 'delivery_status' => OrderDeliveryStatus::IN_TRANSIT, 'total_amount' => 120]);
    // An old delivered parcel outside the 7-day window still counts in the balance.
    makeOrder($admin->business_id, ['delivery_account_id' => $account->id, 'shipped_at' => now()->subDays(40), 'delivery_status' => OrderDeliveryStatus::DELIVERED, 'total_amount' => 100, 'delivery_cost' => 10]);
    // A test order never counts.
    makeOrder($admin->business_id, ['delivery_account_id' => $account->id, 'shipped_at' => now()->subDay(), 'delivery_status' => OrderDeliveryStatus::DELIVERED, 'total_amount' => 999, 'is_test' => true]);

    // Delivery guy: one delivered (200 − 20) not handed over, plus a
    // hand-over that came up 15 short.
    makeOrder($admin->business_id, ['delivery_guy_id' => $guy->id, 'shipped_at' => now()->subDay(), 'delivery_status' => OrderDeliveryStatus::DELIVERED, 'total_amount' => 200, 'delivery_cost' => 20]);
    DeliveryCashSettlement::create([
        'business_id' => $admin->business_id,
        'user_id' => $guy->id,
        'settled_by_user_id' => $admin->id,
        'amount' => 500,
        'received_amount' => 485,
        'difference_amount' => -15,
        'balance_after' => 15,
        'parcels_count' => 3,
    ]);

    $this->actingAs($admin)
        ->get(route('finance.cash.index', ['period' => '7d']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('finance/cash')
            ->where('couriers.0.name', 'Casa account')
            ->where('couriers.0.subtitle', 'OzonExpress')
            ->where('couriers.0.shipped', 3)
            ->where('couriers.0.delivered', 1)
            ->where('couriers.0.returned', 1)
            ->where('couriers.0.inTransit', 1)
            ->where('couriers.0.deliveryRatePct', 33)
            ->where('couriers.0.collectedMad', 300)
            ->where('couriers.0.deliveryCostMad', 30)
            ->where('couriers.0.stillOwedMad', 360)
            ->where('couriers.0.unsettledParcels', 2)
            ->where('couriers.0.lastSettlement', null)
            ->where('deliveryGuys.0.name', 'Hamza')
            ->where('deliveryGuys.0.shipped', 1)
            ->where('deliveryGuys.0.delivered', 1)
            ->where('deliveryGuys.0.collectedMad', 200)
            ->where('deliveryGuys.0.stillOwedMad', 195)
            ->where('deliveryGuys.0.lastSettlement.status', 'short')
            ->where('deliveryGuys.0.lastSettlement.differenceMad', -15)
            ->etc());
});

test('a closed settlement takes its parcels out of the courier balance', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $account = DeliveryAccount::factory()->create(['business_id' => $admin->business_id]);

    makeOrder($admin->business_id, ['delivery_account_id' => $account->id, 'shipped_at' => '2026-09-10 10:00:00', 'delivery_status' => OrderDeliveryStatus::DELIVERED, 'total_amount' => 400, 'delivery_cost' => 40]);
    makeOrder($admin->business_id, ['delivery_account_id' => $account->id, 'shipped_at' => now()->subDay(), 'delivery_status' => OrderDeliveryStatus::DELIVERED, 'total_amount' => 250, 'delivery_cost' => 25]);

    CourierSettlement::create([
        'business_id' => $admin->business_id,
        'delivery_account_id' => $account->id,
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'expected_amount' => 400,
        'actual_amount' => 400,
        'difference_amount' => 0,
        'status' => 'reconciled',
    ]);

    $this->actingAs($admin)
        ->get(route('finance.cash.index'))
        ->assertInertia(fn ($page) => $page
            ->where('couriers.0.stillOwedMad', 225)
            ->where('couriers.0.unsettledParcels', 1)
            ->where('couriers.0.lastSettlement.status', 'matched')
            ->where('couriers.0.lastSettlement.at', '2026-09-30')
            ->etc());
});

test('another business never sees your carriers', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $stranger = makeBusinessUser(['role' => UserRole::ADMIN]);
    DeliveryAccount::factory()->create(['business_id' => $stranger->business_id]);
    User::factory()->create(['business_id' => $stranger->business_id, 'role' => UserRole::DELIVERY_GUY]);

    $this->actingAs($admin)
        ->get(route('finance.cash.index'))
        ->assertInertia(fn ($page) => $page->has('couriers', 0)->has('deliveryGuys', 0));
});
