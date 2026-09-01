<?php

use App\Enums\Courier;
use App\Enums\DeliveryAccountStatus;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeForceLogCredentialCheck(bool $valid): void
{
    Http::fake([
        'api.forcelog.ma/customer/Cities' => Http::response(
            $valid
                ? [
                    'AUTH' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Customer Authenticated'],
                    'Cities' => ['34' => ['CODE' => 'CAS', 'NAME' => 'Casablanca', 'D_FEES' => '30', 'D_FEES_SAME_CITY' => '20']],
                ]
                // A rejected key still comes back HTTP 200 — the body is
                // what says no.
                : ['AUTH' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid API Key']],
            200,
        ),
    ]);
}

function makeForceLogCourier(): DeliveryCourrier
{
    return DeliveryCourrier::create(['name' => 'FORCELOG', 'slug' => Courier::FORCELOG->value]);
}

test('connecting a ForceLog account with a valid api key creates an active delivery account', function () {
    fakeForceLogCredentialCheck(valid: true);

    $admin = makeBusinessUser();
    $courier = makeForceLogCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'api_key' => 'valid-api-key',
    ]);

    $account = DeliveryAccount::where('business_id', $admin->business_id)->firstOrFail();
    $response->assertRedirect(route('delivery-couriers.connected', $account));
    expect($account->status)->toBe(DeliveryAccountStatus::ACTIVE);

    // ForceLog needs only the one key — no id/secret pair like the others.
    $credentials = json_decode($account->api_credentials, true);
    expect($credentials)->toBe(['api_key' => 'valid-api-key']);
});

test('the api key is sent in the X-API-Key header when verifying', function () {
    fakeForceLogCredentialCheck(valid: true);

    $admin = makeBusinessUser();
    $courier = makeForceLogCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'api_key' => 'valid-api-key',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'valid-api-key'));
});

test('connecting a ForceLog account with a rejected api key creates no account', function () {
    fakeForceLogCredentialCheck(valid: false);

    $admin = makeBusinessUser();
    $courier = makeForceLogCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'api_key' => 'bad-api-key',
    ]);

    $response->assertSessionHasErrors('api_key');
    expect(DeliveryAccount::where('business_id', $admin->business_id)->exists())->toBeFalse();
});

test('an http failure while verifying a ForceLog key creates no account', function () {
    Http::fake(['api.forcelog.ma/*' => Http::response([], 500)]);

    $admin = makeBusinessUser();
    $courier = makeForceLogCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'api_key' => 'some-key',
    ]);

    $response->assertSessionHasErrors('api_key');
    expect(DeliveryAccount::where('business_id', $admin->business_id)->exists())->toBeFalse();
});

test('connecting a ForceLog account requires an api key', function () {
    $admin = makeBusinessUser();
    $courier = makeForceLogCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
    ]);

    $response->assertSessionHasErrors('api_key');
});

test('ForceLog does not require the credential fields the other couriers use', function () {
    fakeForceLogCredentialCheck(valid: true);

    $admin = makeBusinessUser();
    $courier = makeForceLogCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'api_key' => 'valid-api-key',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionMissing('errors.ozon_id');
    $response->assertSessionMissing('errors.client_id');
});
