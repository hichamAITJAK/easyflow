<?php

use App\Enums\Courier;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
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
