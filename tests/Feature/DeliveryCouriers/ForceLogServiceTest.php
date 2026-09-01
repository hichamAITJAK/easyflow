<?php

use App\DTOs\ForceLog\ForceLogParcelDTO;
use App\Enums\Courier;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\Order;
use App\Services\Operations\Couriers\ForceLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: DeliveryAccount, 1: DeliveryCourrier, 2: int}
 */
function makeForceLogAccount(): array
{
    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'FORCELOG', 'slug' => Courier::FORCELOG->value]);

    $account = DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['api_key' => 'test-key']),
        'status' => 'active',
    ]);

    return [$account, $courier, $admin->business_id];
}

function fakeForceLogAddParcel(string $tracking = 'F-1234567'): void
{
    Http::fake([
        'api.forcelog.ma/customer/Parcels/AddParcel' => Http::response([
            'AUTH' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Customer Authenticated'],
            'ADD-PARCEL' => [
                'RESULT' => 'SUCCESS',
                'MESSAGE' => 'New Parcel Added Successfully',
                'NEW-PARCEL' => [
                    'TRACKING_NUMBER' => $tracking,
                    'ORDER_NUM' => 'ORD-1042',
                    'RECEIVER' => 'Youssef Alami',
                    'PHONE' => '0600000000',
                    'CITY_NAME' => 'Casablanca',
                    'ADDRESS' => '12 Rue Ibn Batouta',
                    'PRICE' => '299',
                ],
            ],
        ], 200),
    ]);
}

function fakeForceLogCityFees(): void
{
    Http::fake([
        'api.forcelog.ma/customer/Cities' => Http::response([
            'AUTH' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Customer Authenticated'],
            'Cities' => [
                '34' => ['CODE' => 'CAS', 'NAME' => 'Casablanca', 'D_FEES' => '30', 'D_FEES_SAME_CITY' => '20'],
                '99' => ['CODE' => 'RBA', 'NAME' => 'Rabat', 'D_FEES' => '40', 'D_FEES_SAME_CITY' => '25'],
            ],
        ], 200),
        'api.forcelog.ma/customer/Parcels/AddParcel' => Http::response([
            'AUTH' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Customer Authenticated'],
            'ADD-PARCEL' => [
                'RESULT' => 'SUCCESS',
                'MESSAGE' => 'New Parcel Added Successfully',
                'NEW-PARCEL' => ['TRACKING_NUMBER' => 'F-1234567'],
            ],
        ], 200),
    ]);
}

test('the delivery cost is resolved from the account\'s own city pricing', function () {
    // ForceLog's add-parcel response carries no fee, so it is looked up
    // from /customer/Cities using THIS account's key — those rates are
    // negotiated per customer.
    fakeForceLogCityFees();

    [$account, $courier, $businessId] = makeForceLogAccount();
    $city = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Casablanca',
        'external_courrier_id' => '34',
    ]);

    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 299]);

    $parcel = (new ForceLogService($account))->addParcel($order, $city);

    expect($parcel->deliveryCost)->toBe(30.0);

    // The fee lookup must carry the account's key, not a developer one.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/customer/Cities')
        && $request->hasHeader('X-API-Key', 'test-key')
    );
});

test('the same-city rate applies when the destination is the account\'s pickup city', function () {
    // Casablanca is 30 normally but 20 when it never leaves the city.
    fakeForceLogCityFees();

    [$account, $courier, $businessId] = makeForceLogAccount();

    $collectCity = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Casablanca',
        'external_courrier_id' => '34',
    ]);
    $account->update(['collect_city_id' => $collectCity->id]);
    $account->refresh();

    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 299]);

    $parcel = (new ForceLogService($account))->addParcel($order, $collectCity);

    expect($parcel->deliveryCost)->toBe(20.0);
});

test('the standard rate applies when shipping out of the pickup city', function () {
    fakeForceLogCityFees();

    [$account, $courier, $businessId] = makeForceLogAccount();

    $collectCity = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Casablanca',
        'external_courrier_id' => '34',
    ]);
    $destination = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Rabat',
        'external_courrier_id' => '99',
    ]);
    $account->update(['collect_city_id' => $collectCity->id]);
    $account->refresh();

    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 299]);

    $parcel = (new ForceLogService($account))->addParcel($order, $destination);

    expect($parcel->deliveryCost)->toBe(40.0);
});

test('a failed fee lookup does not take the created parcel down with it', function () {
    // The parcel already exists at ForceLog by this point — losing the fee
    // is recoverable, losing the shipment is not.
    Http::fake([
        'api.forcelog.ma/customer/Parcels/AddParcel' => Http::response([
            'ADD-PARCEL' => [
                'RESULT' => 'SUCCESS',
                'NEW-PARCEL' => ['TRACKING_NUMBER' => 'F-1234567'],
            ],
        ], 200),
        'api.forcelog.ma/customer/Cities' => Http::response([], 500),
    ]);

    [$account, $courier, $businessId] = makeForceLogAccount();
    $city = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Casablanca',
        'external_courrier_id' => '34',
    ]);

    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 299]);

    $parcel = (new ForceLogService($account))->addParcel($order, $city);

    expect($parcel->trackingNumber)->toBe('F-1234567');
    expect($parcel->deliveryCost)->toBeNull();
});

test('a city with no external id resolves no fee and makes no lookup call', function () {
    Http::fake([
        'api.forcelog.ma/customer/Parcels/AddParcel' => Http::response([
            'ADD-PARCEL' => ['RESULT' => 'SUCCESS', 'NEW-PARCEL' => ['TRACKING_NUMBER' => 'F-1']],
        ], 200),
    ]);

    [$account, $courier, $businessId] = makeForceLogAccount();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 299]);

    $parcel = (new ForceLogService($account))->addParcel($order, $city);

    expect($parcel->deliveryCost)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/customer/Cities'));
});

test('a city ForceLog no longer prices resolves no fee', function () {
    fakeForceLogCityFees();

    [$account, $courier, $businessId] = makeForceLogAccount();
    $city = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Nowhere',
        'external_courrier_id' => '4242',
    ]);

    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 299]);

    expect((new ForceLogService($account))->addParcel($order, $city)->deliveryCost)->toBeNull();
});

test('a fee from the courier\'s own parcel record is never overwritten by a city quote', function () {
    $parcel = ForceLogParcelDTO::fromArray([
        'TRACKING_NUMBER' => 'F-1',
        'DELIVERY_FEES' => 35,
    ]);

    // 35 is what ForceLog charged for THIS parcel; 30 is only the city's
    // list price.
    expect($parcel->withDeliveryCost(30.0)->deliveryCost)->toBe(35.0);
});

test('addParcel sends the order to ForceLog and returns the tracking number', function () {
    fakeForceLogAddParcel();

    [$account, $courier, $businessId] = makeForceLogAccount();
    $city = DeleveryCourrierCity::create([
        'courrier_id' => $courier->id,
        'name' => 'Casablanca',
        'external_courrier_id' => '34',
    ]);

    $order = Order::factory()->create([
        'business_id' => $businessId,
        'reference' => 'ORD-1042',
        'customer_name' => 'Youssef Alami',
        'customer_phone' => '0600000000',
        'customer_address' => '12 Rue Ibn Batouta',
        'total_amount' => 299,
    ]);

    $parcel = (new ForceLogService($account))->addParcel($order, $city);

    expect($parcel->trackingNumber)->toBe('F-1234567');

    // addParcel also calls /customer/Cities for the fee, so this narrows to
    // the create call before reading its body.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/Parcels/AddParcel')) {
            return false;
        }

        $body = $request->data();

        // The city NAME, not the external id — ForceLog's AddParcel takes
        // a code or name, unlike RelaunchZone which needs the numeric id.
        return $body['CITY'] === 'Casablanca'
            && $body['ORDER_NUM'] === 'ORD-1042'
            && $body['RECEIVER'] === 'Youssef Alami'
            && (float) $body['COD'] === 299.0
            && $request->hasHeader('X-API-Key', 'test-key');
    });
});

test('addParcel falls back to the order\'s own city when none is passed', function () {
    fakeForceLogAddParcel();

    [$account, , $businessId] = makeForceLogAccount();

    $order = Order::factory()->create([
        'business_id' => $businessId,
        'customer_city' => 'Rabat',
        'total_amount' => 100,
    ]);

    (new ForceLogService($account))->addParcel($order, null);

    Http::assertSent(fn ($request) => $request->data()['CITY'] === 'Rabat');
});

test('addParcel throws when no city is available at all', function () {
    [$account, , $businessId] = makeForceLogAccount();

    $order = Order::factory()->create([
        'business_id' => $businessId,
        'customer_city' => null,
    ]);

    (new ForceLogService($account))->addParcel($order, null);
})->throws(InvalidArgumentException::class, 'ForceLog requires a destination city.');

test('a business-level rejection is raised as a RequestException despite the HTTP 200', function () {
    // ForceLog returns 200 for failures, so ->throw() alone catches nothing.
    Http::fake([
        'api.forcelog.ma/customer/Parcels/AddParcel' => Http::response([
            'AUTH' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Customer Authenticated'],
            'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Ville non desservie'],
        ], 200),
    ]);

    [$account, $courier, $businessId] = makeForceLogAccount();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);
    $order = Order::factory()->create(['business_id' => $businessId, 'total_amount' => 50]);

    (new ForceLogService($account))->addParcel($order, $city);
})->throws(RequestException::class);

test('syncCities upserts ForceLog\'s cities keyed on their numeric id', function () {
    // The live response nests the id-keyed map under "Cities", alongside an
    // AUTH envelope — neither of which the published docs mention.
    Http::fake([
        'api.forcelog.ma/customer/Cities' => Http::response([
            'AUTH' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Customer Authenticated'],
            'Cities' => [
                '17' => ['CODE' => 'NDR', 'NAME' => 'Nador', 'D_FEES' => '45', 'D_FEES_SAME_CITY' => '45'],
                '34' => ['CODE' => 'CAS', 'NAME' => 'Casablanca', 'D_FEES' => '30', 'D_FEES_SAME_CITY' => '20'],
            ],
        ], 200),
    ]);

    [$account, $courier] = makeForceLogAccount();

    (new ForceLogService($account))->syncCities();

    // AUTH must never be mistaken for a city.
    expect(DeleveryCourrierCity::where('courrier_id', $courier->id)->count())->toBe(2);

    $casablanca = DeleveryCourrierCity::where('courrier_id', $courier->id)
        ->where('external_courrier_id', '34')
        ->firstOrFail();

    expect($casablanca->name)->toBe('Casablanca');
});

test('syncCities also accepts the bare city map the docs describe', function () {
    Http::fake([
        'api.forcelog.ma/customer/Cities' => Http::response([
            '34' => ['CODE' => 'CAS', 'NAME' => 'Casablanca', 'D_FEES' => '30'],
        ], 200),
    ]);

    [$account, $courier] = makeForceLogAccount();

    (new ForceLogService($account))->syncCities();

    expect(DeleveryCourrierCity::where('courrier_id', $courier->id)->count())->toBe(1);
});

test('syncCities is idempotent', function () {
    Http::fake([
        'api.forcelog.ma/customer/Cities' => Http::response([
            'Cities' => ['34' => ['CODE' => 'CAS', 'NAME' => 'Casablanca', 'D_FEES' => '30']],
        ], 200),
    ]);

    [$account, $courier] = makeForceLogAccount();
    $service = new ForceLogService($account);

    $service->syncCities();
    $service->syncCities();

    expect(DeleveryCourrierCity::where('courrier_id', $courier->id)->count())->toBe(1);
});

test('listParcels unwraps the GET-PARCELS envelope', function () {
    Http::fake([
        'api.forcelog.ma/customer/Parcels/GetParcels*' => Http::response([
            'GET-PARCELS' => [
                'RESULT' => 'SUCCESS',
                'TOTAL' => 2,
                'PAGE' => 1,
                'LIMIT' => 20,
                'PARCELS' => [
                    ['TRACKING_NUMBER' => 'F-1', 'STATUS_CODE' => 'DELIVERED'],
                    ['TRACKING_NUMBER' => 'F-2', 'STATUS_CODE' => 'IN_PROGRESS'],
                ],
            ],
        ], 200),
    ]);

    [$account] = makeForceLogAccount();

    $parcels = (new ForceLogService($account))->listParcels(['LIMIT' => 20]);

    expect($parcels)->toHaveCount(2);
    expect($parcels[0]->trackingNumber)->toBe('F-1');
    expect($parcels[1]->statusCode)->toBe('IN_PROGRESS');
});

test('getParcel reads the GET-PARCEL envelope the live API returns', function () {
    // The docs show RESULT at the top level with no wrapper; the live API
    // wraps it like every other operation.
    Http::fake([
        'api.forcelog.ma/customer/Parcels/GetParcel*' => Http::response([
            'AUTH' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Customer Authenticated'],
            'GET-PARCEL' => [
                'RESULT' => 'SUCCESS',
                'PARCEL' => [
                    'TRACKING_NUMBER' => 'F-1234567',
                    'STATUS' => 'Livré',
                    'SITUATION' => 'Payé',
                    'DELIVERY_FEES' => 30,
                ],
            ],
        ], 200),
    ]);

    [$account] = makeForceLogAccount();

    $parcel = (new ForceLogService($account))->getParcel('F-1234567');

    expect($parcel->status)->toBe('Livré');
    expect($parcel->deliveryCost)->toBe(30.0);
});

test('getParcel also accepts the top-level shape the docs describe', function () {
    Http::fake([
        'api.forcelog.ma/customer/Parcels/GetParcel*' => Http::response([
            'RESULT' => 'SUCCESS',
            'PARCEL' => [
                'TRACKING_NUMBER' => 'F-1234567',
                'STATUS' => 'Livré',
                'DELIVERY_FEES' => 30,
            ],
        ], 200),
    ]);

    [$account] = makeForceLogAccount();

    $parcel = (new ForceLogService($account))->getParcel('F-1234567');

    expect($parcel->trackingNumber)->toBe('F-1234567');
    expect($parcel->deliveryCost)->toBe(30.0);
});

test('a not-found parcel raises a RequestException even on the HTTP 400 the live API returns', function () {
    // The client must not let a 4xx body slip past unnoticed — callers that
    // want to tolerate not-found (getCurrentStatus) inspect the message.
    Http::fake([
        'api.forcelog.ma/customer/Parcels/GetParcel*' => Http::response([
            'GET-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Parcel code Not Found'],
        ], 400),
    ]);

    [$account] = makeForceLogAccount();

    (new ForceLogService($account))->getParcel('F-UNKNOWN');
})->throws(RequestException::class);
