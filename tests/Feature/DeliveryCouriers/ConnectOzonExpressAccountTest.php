<?php

use App\Enums\Courier;
use App\Enums\DeliveryAccountStatus;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeOzonExpressCredentialCheck(bool $valid): void
{
    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response(
            $valid
                ? [
                    'CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valide API Key'],
                    'TRACKING' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Tracking number not found'],
                ]
                : ['CHECK_API' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid API Key']],
            200,
        ),
    ]);
}

test('connecting an OzonExpress account with valid credentials creates an active delivery account', function () {
    fakeOzonExpressCredentialCheck(valid: true);

    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'OzonExpress', 'slug' => Courier::OZONEXPRESS->value]);
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca', 'external_courrier_id' => '12']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'ozon_id' => 'valid-id',
        'api_key' => 'valid-key',
    ]);

    $account = DeliveryAccount::where('business_id', $admin->business_id)->firstOrFail();
    $response->assertRedirect(route('delivery-couriers.connected', $account));
    expect($account->status)->toBe(DeliveryAccountStatus::ACTIVE);
});

test('connecting an OzonExpress account with invalid credentials is rejected and creates no account', function () {
    fakeOzonExpressCredentialCheck(valid: false);

    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'OzonExpress', 'slug' => Courier::OZONEXPRESS->value]);
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca', 'external_courrier_id' => '12']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'ozon_id' => 'bad-id',
        'api_key' => 'bad-key',
    ]);

    $response->assertSessionHasErrors('api_key');
    expect(DeliveryAccount::where('business_id', $admin->business_id)->exists())->toBeFalse();
});
