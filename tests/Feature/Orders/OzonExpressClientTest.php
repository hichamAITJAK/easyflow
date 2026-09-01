<?php

use App\Services\Connectivity\OzonExpress\OzonExpressClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('getTracking returns the full response when TRACKING.RESULT is SUCCESS', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response([
            'CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valide API Key'],
            'TRACKING' => [
                'TRACKING-NUMBER' => 'TET102520881241SM',
                'RESULT' => 'SUCCESS',
                'MESSAGE' => 'Valid tracking number',
                'HISTORY' => [
                    '1' => ['STATUT' => 'Nouveau Colis', 'TIME' => '1759404783', 'TIME_STR' => '2025-10-02 12:33', 'COMMENT' => ''],
                    '18' => ['STATUT' => 'Retourné', 'TIME' => '1760350812', 'TIME_STR' => '2025-10-13 11:20', 'COMMENT' => '| Commentaire: Reçu par client'],
                ],
                'LAST_TRACKING' => ['STATUT' => 'Retourné', 'TIME' => '1760350812', 'TIME_STR' => '2025-10-13 11:20', 'COMMENT' => ' | Commentaire: Reçu par client'],
            ],
        ], 200),
    ]);

    $client = new OzonExpressClient('test-id', 'test-key');

    $response = $client->getTracking('TET102520881241SM');

    expect($response['TRACKING']['RESULT'])->toBe('SUCCESS');
    expect($response['TRACKING']['LAST_TRACKING']['STATUT'])->toBe('Retourné');
});

test('getTracking throws when TRACKING.RESULT is ERROR despite an HTTP 200', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response([
            'CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valide API Key'],
            'TRACKING' => [
                'TRACKING-NUMBER' => 'TET102520881241S',
                'RESULT' => 'ERROR',
                'MESSAGE' => 'Tracking number not found',
            ],
        ], 200),
    ]);

    $client = new OzonExpressClient('test-id', 'test-key');

    $client->getTracking('TET102520881241S');
})->throws(RequestException::class);

test('getParcelInfo returns the full response when PARCEL-INFO.RESULT is SUCCESS', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/parcel-info' => Http::response([
            'CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valide API Key'],
            'PARCEL-INFO' => [
                'CUSTOMER' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valid Customer'],
                'RESULT' => 'SUCCESS',
                'MESSAGE' => 'Parcel Found',
                'INFOS' => [
                    'TRACKING-NUMBER' => 'OUJ102520898276ZQ',
                    'RECEIVER' => 'Ayoub',
                    'PHONE' => '0778176681',
                    'CITY_ID' => '229',
                    'CITY_NAME' => 'Oujda',
                    'ADDRESS' => 'Oujda centre ville',
                    'PRICE' => '199.00',
                    'NOTE' => '',
                    'DELIVERED-PRICE' => '45',
                    'RETURNED-PRICE' => '0',
                    'REFUSED-PRICE' => '10',
                ],
            ],
        ], 200),
    ]);

    $client = new OzonExpressClient('test-id', 'test-key');

    $response = $client->getParcelInfo('OUJ102520898276ZQ');

    expect($response['PARCEL-INFO']['RESULT'])->toBe('SUCCESS');
    expect($response['PARCEL-INFO']['INFOS']['RECEIVER'])->toBe('Ayoub');
});

test('getParcelInfo throws when the error nests its RESULT under ADD-PARCEL instead of PARCEL-INFO', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/parcel-info' => Http::response([
            'ADD-PARCEL' => [
                'CUSTOMER' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valid Customer'],
                'RESULT' => 'ERROR',
                'MESSAGE' => 'Some fields Empty',
            ],
        ], 200),
    ]);

    $client = new OzonExpressClient('test-id', 'test-key');

    $client->getParcelInfo('');
})->throws(RequestException::class);

test('verifyCredentials returns true when CHECK_API.RESULT is SUCCESS, even though the fake tracking number itself is rejected', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response([
            'CHECK_API' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valide API Key'],
            'TRACKING' => [
                'TRACKING-NUMBER' => 'NOSHEET-CREDENTIALS-CHECK',
                'RESULT' => 'ERROR',
                'MESSAGE' => 'Tracking number not found',
            ],
        ], 200),
    ]);

    $client = new OzonExpressClient('valid-id', 'valid-key');

    expect($client->verifyCredentials())->toBeTrue();
});

test('verifyCredentials returns false when CHECK_API.RESULT is not SUCCESS', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response([
            'CHECK_API' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid API Key'],
        ], 200),
    ]);

    $client = new OzonExpressClient('bad-id', 'bad-key');

    expect($client->verifyCredentials())->toBeFalse();
});

test('verifyCredentials returns false when the request fails at the HTTP level', function () {
    Http::fake([
        'api.ozonexpress.ma/customers/*/tracking' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $client = new OzonExpressClient('bad-id', 'bad-key');

    expect($client->verifyCredentials())->toBeFalse();
});
