<?php

use App\Enums\Courier;
use App\Enums\DeliveryAccountStatus;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Ameex answers everything with HTTP 200 — failure lives in the body, in
 * two independent layers.
 */
function fakeAmeexCredentialCheck(bool $valid): void
{
    Http::fake([
        'api.ameex.app/customer/Delivery/Parcels/Statuts' => Http::response(
            $valid
                ? ['login' => 'success', 'api' => ['type' => 'success', 'data' => []]]
                : ['login' => 'error', 'api' => null],
            200,
        ),
    ]);
}

function makeAmeexCourier(): DeliveryCourrier
{
    return DeliveryCourrier::create(['name' => 'Ameex', 'slug' => Courier::AMEEX->value]);
}

test('connecting an Ameex account with valid credentials creates an active delivery account', function () {
    fakeAmeexCredentialCheck(valid: true);

    $admin = makeBusinessUser();
    $courier = makeAmeexCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'api_id' => '2',
        'api_key' => 'valid-api-key',
    ]);

    $account = DeliveryAccount::where('business_id', $admin->business_id)->firstOrFail();
    $response->assertRedirect(route('delivery-couriers.connected', $account));
    expect($account->status)->toBe(DeliveryAccountStatus::ACTIVE);

    $credentials = json_decode($account->api_credentials, true);
    expect($credentials)->toBe(['api_id' => '2', 'api_key' => 'valid-api-key']);
});

test('both credential headers are sent when verifying', function () {
    fakeAmeexCredentialCheck(valid: true);

    $admin = makeBusinessUser();
    $courier = makeAmeexCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'api_id' => '2',
        'api_key' => 'valid-api-key',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('C-Api-Id', '2')
        && $request->hasHeader('C-Api-Key', 'valid-api-key')
    );
});

test('rejected Ameex credentials create no account, despite the HTTP 200', function () {
    // The whole point of this courier's error contract: a 200 that means no.
    fakeAmeexCredentialCheck(valid: false);

    $admin = makeBusinessUser();
    $courier = makeAmeexCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'api_id' => '2',
        'api_key' => 'bad-api-key',
    ]);

    $response->assertSessionHasErrors('api_key');
    expect(DeliveryAccount::where('business_id', $admin->business_id)->exists())->toBeFalse();
});

test('an http failure while verifying creates no account', function () {
    Http::fake(['api.ameex.app/*' => Http::response([], 500)]);

    $admin = makeBusinessUser();
    $courier = makeAmeexCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'api_id' => '2',
        'api_key' => 'some-key',
    ]);

    $response->assertSessionHasErrors('api_key');
    expect(DeliveryAccount::where('business_id', $admin->business_id)->exists())->toBeFalse();
});

test('connecting an Ameex account requires both an api id and an api key', function () {
    $admin = makeBusinessUser();
    $courier = makeAmeexCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
    ]);

    $response->assertSessionHasErrors(['api_id', 'api_key']);
});
