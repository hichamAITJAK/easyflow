<?php

use App\Enums\Courier;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Services\Operations\Couriers\ColiixService;
use App\Services\Operations\Couriers\ForceLogService;
use App\Services\Operations\Couriers\OzonExpressService;
use App\Services\Operations\Couriers\SenditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeAccountFor(string $courierSlug, array $credentials): DeliveryAccount
{
    $courier = DeliveryCourrier::create(['name' => $courierSlug, 'slug' => $courierSlug]);
    $admin = makeBusinessUser();

    return DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => json_encode($credentials),
        'status' => 'active',
    ]);
}

test('SenditService::getCurrentStatus returns the raw status from getParcel', function () {
    Http::fake([
        'app.sendit.ma/api/v1/deliveries/*' => Http::response([
            'data' => ['code' => 'SEND-123', 'status' => 'DELIVERED'],
        ], 200),
    ]);

    $account = makeAccountFor(Courier::SENDIT->value, ['token' => 'test-token']);
    $service = new SenditService($account);

    expect($service->getCurrentStatus('SEND-123'))->toBe('DELIVERED');
});

test('OzonExpressService::getCurrentStatus reads TRACKING.LAST_TRACKING.STATUT', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response([
            'CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valide API Key'],
            'TRACKING' => [
                'TRACKING-NUMBER' => 'OZ-999',
                'RESULT' => 'SUCCESS',
                'MESSAGE' => 'Valid tracking number',
                'HISTORY' => [
                    '1' => ['STATUT' => 'Nouveau Colis', 'TIME' => '1759404783', 'TIME_STR' => '2025-10-02 12:33', 'COMMENT' => ''],
                    '2' => ['STATUT' => 'Livré', 'TIME' => '1760350812', 'TIME_STR' => '2025-10-13 11:20', 'COMMENT' => ''],
                ],
                'LAST_TRACKING' => ['STATUT' => 'Livré', 'TIME' => '1760350812', 'TIME_STR' => '2025-10-13 11:20', 'COMMENT' => ''],
            ],
        ], 200),
    ]);

    $account = makeAccountFor(Courier::OZONEXPRESS->value, ['ozon_id' => 'test-id', 'api_key' => 'test-key']);
    $service = new OzonExpressService($account);

    expect($service->getCurrentStatus('OZ-999'))->toBe('Livré');
});

test('OzonExpressService::getCurrentStatus returns null when the tracking number is unknown', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response([
            'CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valide API Key'],
            'TRACKING' => [
                'TRACKING-NUMBER' => 'OZ-UNKNOWN',
                'RESULT' => 'ERROR',
                'MESSAGE' => 'Tracking number not found',
            ],
        ], 200),
    ]);

    $account = makeAccountFor(Courier::OZONEXPRESS->value, ['ozon_id' => 'test-id', 'api_key' => 'test-key']);
    $service = new OzonExpressService($account);

    expect($service->getCurrentStatus('OZ-UNKNOWN'))->toBeNull();
});

test('OzonExpressService::getCurrentStatus lets a real HTTP-level failure propagate', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response(['error' => 'server error'], 500),
    ]);

    $account = makeAccountFor(Courier::OZONEXPRESS->value, ['ozon_id' => 'test-id', 'api_key' => 'test-key']);
    $service = new OzonExpressService($account);

    $service->getCurrentStatus('OZ-999');
})->throws(RequestException::class);

test('ColiixService::getCurrentStatus returns the last history entry\'s status', function () {
    Http::fake([
        'my.coliix.com/casa/seller/api-parcels' => Http::response([
            'status' => true,
            'msg' => [
                ['code' => 'COL-1', 'status' => 'Nouveau Colis '],
                ['code' => 'COL-1', 'status' => 'Ramassé '],
                ['code' => 'COL-1', 'status' => 'Livré '],
            ],
            'tracking' => 'COL-1',
        ], 200),
    ]);

    $account = makeAccountFor(Courier::COLIIX->value, ['client_id' => 'test-client-id', 'token' => 'test-token']);
    $service = new ColiixService($account);

    expect($service->getCurrentStatus('COL-1'))->toBe('Livré ');
});

test('ColiixService::getCurrentStatus returns null when the history is empty', function () {
    Http::fake([
        'my.coliix.com/casa/seller/api-parcels' => Http::response([
            'status' => true,
            'msg' => [],
            'tracking' => 'COL-2',
        ], 200),
    ]);

    $account = makeAccountFor(Courier::COLIIX->value, ['client_id' => 'test-client-id', 'token' => 'test-token']);
    $service = new ColiixService($account);

    expect($service->getCurrentStatus('COL-2'))->toBeNull();
});

test('ForceLogService::getCurrentStatus returns the last history entry\'s status code', function () {
    // The code, not the French STATUS_NAME — it is the stable identifier.
    Http::fake([
        'api.forcelog.ma/customer/Parcels/GetTracking*' => Http::response([
            'GET-TRACKING' => [
                'RESULT' => 'SUCCESS',
                'MESSAGE' => 'Parcel history found',
                'TRACKING_NUMBER' => 'F-1234567',
                'HISTORY' => [
                    ['STATUS_CODE' => 'NEW_PARCEL', 'STATUS_NAME' => 'Nouveau', 'CITY_NAME' => '', 'TIMESTAMP' => 1735689600],
                    ['STATUS_CODE' => 'DELIVERED', 'STATUS_NAME' => 'Livré', 'CITY_NAME' => 'Casablanca', 'TIMESTAMP' => 1735776000],
                ],
            ],
        ], 200),
    ]);

    $account = makeAccountFor(Courier::FORCELOG->value, ['api_key' => 'test-key']);
    $service = new ForceLogService($account);

    expect($service->getCurrentStatus('F-1234567'))->toBe('DELIVERED');
});

test('ForceLogService::getCurrentStatus returns null when the history is empty', function () {
    Http::fake([
        'api.forcelog.ma/customer/Parcels/GetTracking*' => Http::response([
            'GET-TRACKING' => [
                'RESULT' => 'SUCCESS',
                'TRACKING_NUMBER' => 'F-1234567',
                'HISTORY' => [],
            ],
        ], 200),
    ]);

    $account = makeAccountFor(Courier::FORCELOG->value, ['api_key' => 'test-key']);
    $service = new ForceLogService($account);

    expect($service->getCurrentStatus('F-1234567'))->toBeNull();
});

test('ForceLogService::getCurrentStatus returns null when the tracking number is unknown', function () {
    // The live API reports "not found" as a real HTTP 400 carrying an error
    // envelope — a data problem for one order, not a reason to fail the
    // whole poll run.
    Http::fake([
        'api.forcelog.ma/customer/Parcels/GetTracking*' => Http::response([
            'AUTH' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Customer Authenticated'],
            'GET-TRACKING' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Parcel code Not Found'],
        ], 400),
    ]);

    $account = makeAccountFor(Courier::FORCELOG->value, ['api_key' => 'test-key']);
    $service = new ForceLogService($account);

    expect($service->getCurrentStatus('F-UNKNOWN'))->toBeNull();
});

test('ForceLogService::getCurrentStatus also tolerates a not-found reported as HTTP 200', function () {
    // The published docs describe this shape; the live API uses a 400.
    // Both must resolve to "no change".
    Http::fake([
        'api.forcelog.ma/customer/Parcels/GetTracking*' => Http::response([
            'GET-TRACKING' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Parcel Not Found'],
        ], 200),
    ]);

    $account = makeAccountFor(Courier::FORCELOG->value, ['api_key' => 'test-key']);
    $service = new ForceLogService($account);

    expect($service->getCurrentStatus('F-UNKNOWN'))->toBeNull();
});

test('ForceLogService::getCurrentStatus propagates a 4xx that is not a not-found', function () {
    // A client-side error that isn't "this parcel doesn't exist" is a real
    // failure and must not be swallowed as "no change".
    Http::fake([
        'api.forcelog.ma/customer/Parcels/GetTracking*' => Http::response([
            'GET-TRACKING' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid API Key'],
        ], 400),
    ]);

    $account = makeAccountFor(Courier::FORCELOG->value, ['api_key' => 'test-key']);
    $service = new ForceLogService($account);

    $service->getCurrentStatus('F-1234567');
})->throws(RequestException::class);

test('ForceLogService::getCurrentStatus lets a real HTTP-level failure propagate', function () {
    Http::fake([
        'api.forcelog.ma/*' => Http::response(['error' => 'server error'], 500),
    ]);

    $account = makeAccountFor(Courier::FORCELOG->value, ['api_key' => 'test-key']);
    $service = new ForceLogService($account);

    $service->getCurrentStatus('F-1234567');
})->throws(RequestException::class);
