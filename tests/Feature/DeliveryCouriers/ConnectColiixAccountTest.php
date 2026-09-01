<?php

use App\Enums\Courier;
use App\Enums\DeliveryAccountStatus;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeColiixCredentialCheck(bool $valid): void
{
    Http::fake([
        'my.coliix.com/casa/seller/api-parcels' => Http::response(
            $valid
                ? ['status' => true, 'msg' => [], 'tracking' => 'NOSHEET-CREDENTIALS-CHECK']
                : ['status' => 204, 'msg' => 'Le compte est désactivé'],
            200,
        ),
    ]);
}

test('connecting a Coliix account with valid credentials creates an active delivery account', function () {
    fakeColiixCredentialCheck(valid: true);

    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Coliix', 'slug' => Courier::COLIIX->value]);
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'client_id' => 'valid-client-id',
        'api_key' => 'valid-token',
    ]);

    $account = DeliveryAccount::where('business_id', $admin->business_id)->firstOrFail();
    $response->assertRedirect(route('delivery-couriers.connected', $account));
    expect($account->status)->toBe(DeliveryAccountStatus::ACTIVE);

    $credentials = json_decode($account->api_credentials, true);
    expect($credentials)->toBe(['client_id' => 'valid-client-id', 'token' => 'valid-token']);
});

test('connecting a Coliix account with invalid credentials is rejected and creates no account', function () {
    fakeColiixCredentialCheck(valid: false);

    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Coliix', 'slug' => Courier::COLIIX->value]);
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'client_id' => 'bad-client-id',
        'api_key' => 'bad-token',
    ]);

    $response->assertSessionHasErrors('api_key');
    expect(DeliveryAccount::where('business_id', $admin->business_id)->exists())->toBeFalse();
});

test('connecting a Coliix account requires a client id and api key', function () {
    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Coliix', 'slug' => Courier::COLIIX->value]);
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
    ]);

    $response->assertSessionHasErrors(['client_id', 'api_key']);
});
