<?php

use App\Enums\Courier;
use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\EcommercePlatform;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the parcels table receives the courier name and the account label', function () {
    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);
    $account = DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => 'Casablanca contract',
        'api_credentials' => 'token',
        'status' => 'active',
    ]);

    makeOrder($admin->business_id, [
        'delivery_account_id' => $account->id,
        'courier_tracking_number' => 'TRK-1',
        'delivery_status' => 'in_transit',
        'is_delivery_active' => true,
    ]);

    // The Courier column stacks the carrier over the account label, so both
    // have to survive serialization — a business can hold several accounts
    // with one courier, and the carrier name alone doesn't say which shipped.
    $this->actingAs($admin)
        ->get(route('parcels.index'))
        ->assertInertia(fn ($page) => $page
            ->component('parcels/index')
            ->where('parcels.data.0.delivery_account.courier.name', 'Sendit')
            ->where('parcels.data.0.delivery_account.label', 'Casablanca contract')
        );
});

test('a fulfilment agent sees the business parcels, narrowed by store grants', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser([
        'role' => UserRole::FULFILMENT_AGENT,
        'business_id' => $admin->business_id,
    ]);

    makeOrder($admin->business_id, ['courier_tracking_number' => 'TRK-A']);
    makeOrder($admin->business_id, ['courier_tracking_number' => 'TRK-B']);
    makeOrder($admin->business_id, []); // not shipped: not a parcel

    // Never assigned any order, yet the list is not empty for them.
    $this->actingAs($agent)
        ->get(route('parcels.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('parcels.data', 2)->etc());

    // A store grant narrows the list to that store's parcels.
    $platform = EcommercePlatform::create(['name' => 'Shopify', 'slug' => 'shopify']);
    $store = Store::create([
        'business_id' => $admin->business_id,
        'platform_id' => $platform->id,
        'name' => 'Main',
        'slug' => 'main',
        'connection_status' => 'connected',
    ]);
    makeOrder($admin->business_id, ['store_id' => $store->id, 'courier_tracking_number' => 'TRK-C']);
    AgentScope::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'store_id' => $store->id,
    ]);

    $this->actingAs($agent)
        ->get(route('parcels.index'))
        ->assertInertia(fn ($page) => $page->has('parcels.data', 1)->etc());
});
