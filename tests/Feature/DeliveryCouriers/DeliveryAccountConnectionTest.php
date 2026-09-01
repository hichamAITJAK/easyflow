<?php

use App\Enums\Courier;
use App\Enums\DeliveryAccountStatus;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeBusinessWithCouriers(): array
{
    $user = makeBusinessUser();

    $sendit = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);
    $ozon = DeliveryCourrier::create(['name' => 'OzonExpress', 'slug' => Courier::OZONEXPRESS->value]);
    $senditCity = DeleveryCourrierCity::create(['courrier_id' => $sendit->id, 'name' => 'Casablanca']);
    $ozonCity = DeleveryCourrierCity::create(['courrier_id' => $ozon->id, 'name' => 'Rabat']);

    return compact('user', 'sendit', 'ozon', 'senditCity', 'ozonCity');
}

test('valid sendit credentials create an active delivery account', function () {
    ['user' => $user, 'sendit' => $sendit, 'senditCity' => $city] = makeBusinessWithCouriers();

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['data' => ['token' => 'session-token', 'name' => 'My Sendit Account']], 200),
        'app.sendit.ma/api/v1/districts*' => Http::response(['data' => [['id' => 1, 'name' => 'Casablanca']]], 200),
    ]);

    $response = $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Main Sendit account',
        'courier_id' => $sendit->id,
        'collect_city_id' => $city->id,
        'public_key' => 'valid-public-key',
        'secret_key' => 'valid-secret-key',
    ]);

    $account = DeliveryAccount::first();
    $response->assertRedirect(route('delivery-couriers.connected', $account));
    expect($account->status)->toBe(DeliveryAccountStatus::ACTIVE);
    expect($account->label)->toBe('Main Sendit account');
    expect($account->collect_city_id)->toBe($city->id);
    expect($account->is_default)->toBeTrue();
});

test('invalid sendit credentials are rejected and no account is created', function () {
    ['user' => $user, 'sendit' => $sendit, 'senditCity' => $city] = makeBusinessWithCouriers();

    Http::fake(['app.sendit.ma/api/v1/login' => Http::response(['message' => 'Unauthenticated'], 401)]);

    $response = $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Main Sendit account',
        'courier_id' => $sendit->id,
        'collect_city_id' => $city->id,
        'public_key' => 'bad-public-key',
        'secret_key' => 'bad-secret-key',
    ]);

    $response->assertSessionHasErrors('secret_key');
    expect(DeliveryAccount::count())->toBe(0);
});

test('a sendit connection failure surfaces a distinct error', function () {
    ['user' => $user, 'sendit' => $sendit, 'senditCity' => $city] = makeBusinessWithCouriers();

    Http::fake(function () {
        throw new ConnectionException('Could not connect to host.');
    });

    $response = $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Main Sendit account',
        'courier_id' => $sendit->id,
        'collect_city_id' => $city->id,
        'public_key' => 'any-public-key',
        'secret_key' => 'any-secret-key',
    ]);

    $response->assertSessionHasErrors('secret_key');
    expect(session('errors')->get('secret_key')[0])->toContain('Could not reach Sendit');
    expect(DeliveryAccount::count())->toBe(0);
});

test('valid ozonexpress credentials create an active delivery account', function () {
    ['user' => $user, 'ozon' => $ozon, 'ozonCity' => $city] = makeBusinessWithCouriers();

    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response([
            'CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valide API Key'],
            'TRACKING' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Tracking number not found'],
        ], 200),
    ]);

    $response = $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Main OzonExpress account',
        'courier_id' => $ozon->id,
        'collect_city_id' => $city->id,
        'ozon_id' => 'ozon-customer-1',
        'api_key' => 'ozon-key',
    ]);

    $account = DeliveryAccount::first();
    $response->assertRedirect(route('delivery-couriers.connected', $account));
    expect($account->status)->toBe(DeliveryAccountStatus::ACTIVE);
});

test('invalid ozonexpress credentials are rejected and no account is created', function () {
    ['user' => $user, 'ozon' => $ozon, 'ozonCity' => $city] = makeBusinessWithCouriers();

    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response([
            'CHECK_API' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid API Key'],
        ], 200),
    ]);

    $response = $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Main OzonExpress account',
        'courier_id' => $ozon->id,
        'collect_city_id' => $city->id,
        'ozon_id' => 'bad-id',
        'api_key' => 'bad-key',
    ]);

    $response->assertSessionHasErrors('api_key');
    expect(DeliveryAccount::count())->toBe(0);
});

test('a collect city belonging to a different courier is rejected', function () {
    ['user' => $user, 'sendit' => $sendit, 'ozonCity' => $wrongCity] = makeBusinessWithCouriers();

    $response = $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Main Sendit account',
        'courier_id' => $sendit->id,
        'collect_city_id' => $wrongCity->id,
        'public_key' => 'any-public-key',
        'secret_key' => 'any-secret-key',
    ]);

    $response->assertSessionHasErrors('collect_city_id');
    expect(DeliveryAccount::count())->toBe(0);
});

test('a business can connect multiple accounts to the same courier with different labels', function () {
    ['user' => $user, 'sendit' => $sendit, 'senditCity' => $city] = makeBusinessWithCouriers();

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['data' => ['token' => 'session-token']], 200),
        'app.sendit.ma/api/v1/districts*' => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Casablanca warehouse',
        'courier_id' => $sendit->id,
        'collect_city_id' => $city->id,
        'public_key' => 'public-key-1',
        'secret_key' => 'secret-key-1',
    ])->assertRedirect(route('delivery-couriers.connected', DeliveryAccount::first()));

    $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Rabat warehouse',
        'courier_id' => $sendit->id,
        'collect_city_id' => $city->id,
        'public_key' => 'public-key-2',
        'secret_key' => 'secret-key-2',
    ])->assertRedirect(route('delivery-couriers.connected', DeliveryAccount::orderByDesc('id')->first()));

    expect(DeliveryAccount::where('courier_id', $sendit->id)->count())->toBe(2);
});

test('connecting a second account with a label already used for that courier is rejected', function () {
    ['user' => $user, 'sendit' => $sendit, 'senditCity' => $city] = makeBusinessWithCouriers();

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['data' => ['token' => 'session-token']], 200),
        'app.sendit.ma/api/v1/districts*' => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $sendit->id,
        'collect_city_id' => $city->id,
        'public_key' => 'public-key-1',
        'secret_key' => 'secret-key-1',
    ])->assertRedirect(route('delivery-couriers.connected', DeliveryAccount::first()));

    $response = $this->actingAs($user)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $sendit->id,
        'collect_city_id' => $city->id,
        'public_key' => 'public-key-2',
        'secret_key' => 'secret-key-2',
    ]);

    $response->assertSessionHasErrors('label');
    expect(DeliveryAccount::where('courier_id', $sendit->id)->count())->toBe(1);
});
