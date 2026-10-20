<?php

use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\CommissionRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function localGuy(int $businessId, string $city): User
{
    $guy = makeBusinessUser(['role' => UserRole::DELIVERY_GUY, 'business_id' => $businessId]);
    $guy->forceFill(['delivery_city' => $city])->save();
    CommissionRule::factory()->create(['business_id' => $businessId, 'user_id' => $guy->id, 'trigger_status' => 'delivered', 'amount' => 10]);

    return $guy;
}

test('the warehouse lists local parcels to prepare, within its store scope', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $faizat = makeStore($admin->business_id, ['name' => 'Faizat']);
    $handk = makeStore($admin->business_id, ['name' => 'HandK']);
    $agadir = localGuy($admin->business_id, 'Agadir');
    $warehouse = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT, 'business_id' => $admin->business_id]);
    AgentScope::create(['business_id' => $admin->business_id, 'user_id' => $warehouse->id, 'store_id' => $faizat->id, 'product_id' => null]);

    makeOrder($admin->business_id, ['store_id' => $faizat->id, 'delivery_guy_id' => $agadir->id, 'courier_tracking_number' => 'LOC-AGA-1', 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP]);
    makeOrder($admin->business_id, ['store_id' => $faizat->id, 'delivery_guy_id' => $agadir->id, 'courier_tracking_number' => 'LOC-AGA-2', 'delivery_status' => OrderDeliveryStatus::READY_FOR_PICKUP]);
    // Another store: outside this agent's scope.
    makeOrder($admin->business_id, ['store_id' => $handk->id, 'delivery_guy_id' => $agadir->id, 'courier_tracking_number' => 'LOC-AGA-3', 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP]);
    // A courier parcel: not local.
    makeOrder($admin->business_id, ['store_id' => $faizat->id, 'courier_tracking_number' => 'TRK-1', 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP]);

    $this->actingAs($warehouse)->get(route('fulfillment.local'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('fulfillment/local')
            ->where('filter', 'to_prepare')
            ->has('parcels', 1)
            ->where('parcels.0.courier_tracking_number', 'LOC-AGA-1')
            ->where('parcels.0.delivery_guy.delivery_city', 'Agadir')
            ->where('counts.to_prepare', 1)
            ->where('counts.ready', 1)
            ->etc());

    $this->actingAs($warehouse)->get(route('fulfillment.local', ['status' => 'all']))
        ->assertInertia(fn ($page) => $page->has('parcels', 2)->etc());

    $this->actingAs($admin)->get(route('fulfillment.local'))->assertOk();
    $confirmer = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $this->actingAs($confirmer)->get(route('fulfillment.local'))->assertForbidden();
});

test('tickets come out as one PDF, only for the business\'s own local parcels', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $guy = localGuy($admin->business_id, 'Agadir');
    $warehouse = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT, 'business_id' => $admin->business_id]);

    $mine = makeOrder($admin->business_id, [
        'delivery_guy_id' => $guy->id,
        'courier_tracking_number' => 'LOC-AGA-7K2M9P',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
        'parcel_note' => 'just test',
        'parcel_products' => [['ref' => 'HOODIE-M', 'qnty' => 1]],
        'total_amount' => 199,
    ]);
    $theirs = makeOrder(makeBusinessUser()->business_id, ['delivery_guy_id' => $guy->id, 'courier_tracking_number' => 'LOC-X']);

    $this->actingAs($warehouse)->get(route('fulfillment.tickets', ['ids' => "{$mine->id},{$theirs->id}"]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($warehouse)->get(route('fulfillment.tickets', ['ids' => (string) $theirs->id]))
        ->assertNotFound();
});
