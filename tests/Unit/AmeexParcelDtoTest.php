<?php

use App\DTOs\Ameex\AmeexNewParcelDTO;
use App\DTOs\Ameex\AmeexParcelDTO;

test('each boolean flag is emitted in the spelling its own field expects', function () {
    // Ameex is inconsistent by design: open/try take YES/NO, fragile takes
    // 1/0, replace takes "true"/"false".
    $payload = (new AmeexNewParcelDTO(
        receiver: 'Youssef',
        phone: '0600000000',
        city: '1',
        address: '12 Rue',
        cod: 499,
        canOpen: true,
        canTry: false,
        fragile: true,
        replace: false,
    ))->toArray();

    expect($payload['open'])->toBe('YES');
    expect($payload['try'])->toBe('NO');
    expect($payload['fragile'])->toBe(1);
    expect($payload['replace'])->toBe('false');
});

test('null optional fields are dropped but zero-valued flags survive', function () {
    $payload = (new AmeexNewParcelDTO(
        receiver: 'Youssef',
        phone: '0600000000',
        city: '1',
        address: '12 Rue',
        cod: 499,
    ))->toArray();

    expect($payload)->not->toHaveKey('comment');
    expect($payload)->not->toHaveKey('exchange_code');
    expect($payload)->not->toHaveKey('business');
    // fragile is 0 here, which array_filter must not strip.
    expect($payload['fragile'])->toBe(0);
});

test('stock products are flattened into indexed form-data keys', function () {
    $payload = (new AmeexNewParcelDTO(
        receiver: 'Youssef',
        phone: '0600000000',
        city: '1',
        address: '12 Rue',
        cod: 499,
        type: 'STOCK',
        products: [
            ['id' => '4aad2a54-3064-11ef-9ede-3ceceff12da9', 'qty' => 10],
            ['id' => 'other-product-id', 'qty' => 2],
        ],
    ))->toArray();

    expect($payload['products[0][id]'])->toBe('4aad2a54-3064-11ef-9ede-3ceceff12da9');
    expect($payload['products[0][qty]'])->toBe(10);
    expect($payload['products[1][id]'])->toBe('other-product-id');
    expect($payload['type'])->toBe('STOCK');
});

test('the parcel code is read from an unwrapped api payload', function () {
    $dto = AmeexParcelDTO::fromArray([
        'type' => 'success',
        'parcel' => ['code' => 'MRK0824B2LP5041691', 'receiver' => 'Youssef', 'cod' => '499'],
    ]);

    expect($dto->trackingNumber)->toBe('MRK0824B2LP5041691');
    expect($dto->receiver)->toBe('Youssef');
    expect($dto->price)->toBe(499.0);
});

test('a bare parcel object with no nesting is accepted', function () {
    $dto = AmeexParcelDTO::fromArray(['code' => 'MRK-1', 'statut' => 'Livré']);

    expect($dto->trackingNumber)->toBe('MRK-1');
    expect($dto->status)->toBe('Livré');
});

test('alternative key spellings are accepted for the parcel code', function () {
    // The vendor collection ships no example responses and the live API
    // can't be read without credentials, so the exact casing is unverified.
    expect(AmeexParcelDTO::fromArray(['parcel_code' => 'MRK-2'])->trackingNumber)->toBe('MRK-2');
    expect(AmeexParcelDTO::fromArray(['ParcelCode' => 'MRK-3'])->trackingNumber)->toBe('MRK-3');
    expect(AmeexParcelDTO::fromArray(['tracking_number' => 'MRK-4'])->trackingNumber)->toBe('MRK-4');
});

test('Ameex quotes no per-outcome pricing', function () {
    // No documented fee field of any kind, same as Coliix.
    $dto = AmeexParcelDTO::fromArray(['code' => 'MRK-1']);

    expect($dto->deliveryCost)->toBeNull();
    expect($dto->returnedCost)->toBeNull();
    expect($dto->refusedCost)->toBeNull();
});

test('a missing parcel code yields an empty tracking number rather than throwing', function () {
    expect(AmeexParcelDTO::fromArray([])->trackingNumber)->toBe('');
});

test('a parcel list unwraps the data array and skips non-array entries', function () {
    $parcels = AmeexParcelDTO::fromList([
        'data' => [
            ['code' => 'MRK-1'],
            'unexpected-scalar',
            ['code' => 'MRK-2'],
        ],
    ]);

    expect($parcels)->toHaveCount(2);
    expect($parcels[1]->trackingNumber)->toBe('MRK-2');
});
