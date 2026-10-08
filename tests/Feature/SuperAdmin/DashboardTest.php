<?php

use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\OrderStatusEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('only a super admin can open the platform dashboard, and the home route lands there', function () {
    $this->actingAs(superAdmin())->get(route('super-admin.home'))
        ->assertRedirect(route('super-admin.dashboard'));

    $this->actingAs(superAdmin())->get(route('super-admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/dashboard')
            ->where('filters.period', '30d')
            ->has('trend.buckets', 30)
            ->has('businesses.rows')
            ->has('parcels.byBusiness')
            ->has('team.confirmers'));

    $this->actingAs(makeBusinessUser())->get(route('super-admin.dashboard'))->assertForbidden();
});

test('the cards aggregate every business and filter down to one', function () {
    $adminA = makeBusinessUser();
    $adminB = makeBusinessUser();
    $a = $adminA->business_id;
    $b = $adminB->business_id;

    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => 'sendit']);
    $account = DeliveryAccount::create([
        'business_id' => $a, 'courier_id' => $courier->id, 'label' => 'main', 'api_credentials' => '{}', 'status' => 'active',
    ]);

    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $a]);
    $warehouse = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT, 'business_id' => $a]);

    // Business A: 3 orders — one in progress, one delivered, one returned.
    makeOrder($a, ['confirmation_status' => 'assigned', 'assigned_agent_id' => $agent->id]);
    $delivered = makeOrder($a, ['confirmation_status' => 'submitted_to_courier', 'delivery_status' => OrderDeliveryStatus::DELIVERED, 'delivery_account_id' => $account->id, 'assigned_agent_id' => $agent->id]);
    makeOrder($a, ['confirmation_status' => 'submitted_to_courier', 'delivery_status' => OrderDeliveryStatus::RETURN_RECEIVED, 'delivery_account_id' => $account->id, 'assigned_agent_id' => $agent->id]);
    // Business B: one plain order, plus a test order that must never count.
    makeOrder($b, ['confirmation_status' => 'new']);
    makeOrder($b, ['confirmation_status' => 'new', 'is_test' => true]);

    OrderStatusEvent::create(['business_id' => $a, 'order_id' => $delivered->id, 'from_status' => 'awaiting_pickup', 'to_status' => 'ready_for_pickup', 'changed_by_user_id' => $warehouse->id]);
    CommissionLedgerEntry::create(['business_id' => $a, 'user_id' => $agent->id, 'order_id' => $delivered->id, 'amount' => 12.4, 'entry_type' => 'earned', 'created_at' => now()]);
    CommissionLedgerEntry::create(['business_id' => $a, 'user_id' => $warehouse->id, 'order_id' => $delivered->id, 'amount' => 3, 'entry_type' => 'earned', 'created_at' => now()]);

    $this->actingAs(superAdmin())->get(route('super-admin.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('trend.totalOrders', 4)
            ->where('businesses.totals', ['inProgress' => 2, 'confirmedPending' => 0, 'delivered' => 1, 'returned' => 1])
            ->has('businesses.rows', 2)
            ->where('businesses.rows.0.id', $a)
            ->where('businesses.rows.0.orders', 3)
            ->where('businesses.rows.0.confirmed', [2, 66.7])
            ->where('businesses.rows.0.delivered', [1, 50])
            ->where('parcels.total', 2)
            ->where('parcels.byBusiness.0.couriers.0.name', 'Sendit')
            ->where('parcels.byBusiness.0.couriers.0.logoUrl', '/assets/images/sendit_icon.png')
            ->has('parcels.byCourier', 1)
            ->where('parcels.byCourier.0.parcels', 2)
            ->where('parcels.byCourier.0.businesses', ['Acme'])
            ->has('team.confirmers', 1)
            ->where('team.confirmers.0.orders', 3)
            ->where('team.confirmers.0.commissionsMad', 12)
            ->has('team.fulfilment', 1)
            ->where('team.fulfilment.0.parcels', 1)
            ->where('team.fulfilment.0.commissionsMad', 3)
            ->etc());

    $this->actingAs(superAdmin())->get(route('super-admin.dashboard', ['business' => $b, 'period' => 'today']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.business', (string) $b)
            ->where('trend.totalOrders', 1)
            ->has('trend.buckets', 24)
            ->has('businesses.rows', 1)
            ->where('businesses.rows.0.id', $b)
            ->has('parcels.byCourier', 0)
            ->has('team.confirmers', 0)
            ->etc());

    // Custom range echoes its bounds and builds one bucket per day.
    $this->actingAs(superAdmin())->get(route('super-admin.dashboard', [
        'period' => 'custom', 'date_from' => now()->subDays(4)->toDateString(), 'date_to' => now()->toDateString(),
    ]))->assertInertia(fn ($page) => $page
        ->where('filters.date_from', now()->subDays(4)->toDateString())
        ->has('trend.buckets', 5)
        ->etc());
});
