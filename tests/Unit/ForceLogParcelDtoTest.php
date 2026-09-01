<?php

use App\DTOs\ForceLog\ForceLogCityDTO;
use App\DTOs\ForceLog\ForceLogNewParcelDTO;
use App\DTOs\ForceLog\ForceLogParcelDTO;

test('the add-parcel NEW-PARCEL envelope is unwrapped', function () {
    $dto = ForceLogParcelDTO::fromArray([
        'RESULT' => 'SUCCESS',
        'MESSAGE' => 'New Parcel Added Successfully',
        'NEW-PARCEL' => [
            'TRACKING_NUMBER' => 'F-1234567',
            'ORDER_NUM' => 'ORD-1042',
            'RECEIVER' => 'Youssef Alami',
            'PHONE' => '0600000000',
            'CITY_NAME' => 'Casablanca',
            'ADDRESS' => '12 Rue Ibn Batouta',
            'PRICE' => '299',
        ],
    ]);

    expect($dto->trackingNumber)->toBe('F-1234567');
    expect($dto->orderNum)->toBe('ORD-1042');
    expect($dto->price)->toBe(299.0);
});

test('the get-parcel PARCEL envelope is unwrapped and delivery fees are read', function () {
    $dto = ForceLogParcelDTO::fromArray([
        'RESULT' => 'SUCCESS',
        'PARCEL' => [
            'TRACKING_NUMBER' => 'F-1234567',
            'STATUS' => 'Livré',
            'SITUATION' => 'Payé',
            'DELIVERY_FEES' => 30,
            'CAN_OPEN' => 'YES',
        ],
    ]);

    expect($dto->trackingNumber)->toBe('F-1234567');
    expect($dto->status)->toBe('Livré');
    expect($dto->deliveryCost)->toBe(30.0);
    expect($dto->canOpen)->toBeTrue();
});

test('a bare parcel object with no envelope is accepted', function () {
    // This is the shape GetParcels returns for each entry of PARCELS.
    $dto = ForceLogParcelDTO::fromArray([
        'TRACKING_NUMBER' => 'F-1234567',
        'STATUS_CODE' => 'DELIVERED',
    ]);

    expect($dto->trackingNumber)->toBe('F-1234567');
    expect($dto->statusCode)->toBe('DELIVERED');
});

test('ForceLog quotes no returned or refused cost', function () {
    // Unlike OzonExpress, ForceLog has no per-outcome pricing at all.
    $dto = ForceLogParcelDTO::fromArray(['TRACKING_NUMBER' => 'F-1', 'DELIVERY_FEES' => 30]);

    expect($dto->returnedCost)->toBeNull();
    expect($dto->refusedCost)->toBeNull();
});

test('CAN_OPEN is normalized from either its string or numeric spelling', function () {
    expect(ForceLogParcelDTO::fromArray(['CAN_OPEN' => 'YES'])->canOpen)->toBeTrue();
    expect(ForceLogParcelDTO::fromArray(['CAN_OPEN' => 'NO'])->canOpen)->toBeFalse();
    expect(ForceLogParcelDTO::fromArray(['CAN_OPEN' => 1])->canOpen)->toBeTrue();
    expect(ForceLogParcelDTO::fromArray(['CAN_OPEN' => 0])->canOpen)->toBeFalse();
    expect(ForceLogParcelDTO::fromArray([])->canOpen)->toBeNull();
});

test('a parcel list unwraps the PARCELS array', function () {
    $parcels = ForceLogParcelDTO::fromList([
        'RESULT' => 'SUCCESS',
        'TOTAL' => 2,
        'PARCELS' => [
            ['TRACKING_NUMBER' => 'F-1'],
            ['TRACKING_NUMBER' => 'F-2'],
        ],
    ]);

    expect($parcels)->toHaveCount(2);
    expect($parcels[1]->trackingNumber)->toBe('F-2');
});

test('new-parcel fields are truncated to ForceLog column widths', function () {
    // ForceLog rejects the whole request when a field is over-length, so
    // over-long values are cut rather than allowed to fail the shipment.
    $payload = (new ForceLogNewParcelDTO(
        orderNum: 'ORD-1042',
        receiver: str_repeat('a', 80),
        phone: '0600000000',
        city: 'Casablanca',
        address: str_repeat('b', 140),
        comment: str_repeat('c', 160),
        productNature: str_repeat('d', 160),
    ))->toArray();

    expect(mb_strlen($payload['RECEIVER']))->toBe(50);
    expect(mb_strlen($payload['ADDRESS']))->toBe(100);
    expect(mb_strlen($payload['COMMENT']))->toBe(100);
    expect(mb_strlen($payload['PRODUCT_NATURE']))->toBe(100);
});

test('the order number is never truncated', function () {
    // It is the merchant's reconciliation key — a silently shortened value
    // would be worse than the API rejecting the request.
    $payload = (new ForceLogNewParcelDTO(
        orderNum: str_repeat('9', 30),
        receiver: 'Youssef',
        phone: '0600000000',
        city: 'Casablanca',
        address: '12 Rue Ibn Batouta',
    ))->toArray();

    expect($payload['ORDER_NUM'])->toBe(str_repeat('9', 30));
});

test('multibyte names are cut on character boundaries, not bytes', function () {
    $payload = (new ForceLogNewParcelDTO(
        orderNum: 'ORD-1',
        receiver: str_repeat('é', 80),
        phone: '0600000000',
        city: 'Casablanca',
        address: 'x',
    ))->toArray();

    expect(mb_strlen($payload['RECEIVER']))->toBe(50);
    expect($payload['RECEIVER'])->toBe(str_repeat('é', 50));
});

test('null optional fields are dropped from the payload', function () {
    $payload = (new ForceLogNewParcelDTO(
        orderNum: 'ORD-1',
        receiver: 'Youssef',
        phone: '0600000000',
        city: 'Casablanca',
        address: '12 Rue',
    ))->toArray();

    expect($payload)->not->toHaveKey('COMMENT');
    expect($payload)->not->toHaveKey('STOCK');
    expect($payload)->not->toHaveKey('CARTON');
    // Booleans are sent as 1/0, and false must survive the null filter.
    expect($payload['CAN_OPEN'])->toBe(1);
    expect($payload['FRAGILE'])->toBe(0);
});

test('stock references are flattened to a comma-separated string', function () {
    $payload = (new ForceLogNewParcelDTO(
        orderNum: 'ORD-1',
        receiver: 'Youssef',
        phone: '0600000000',
        city: 'Casablanca',
        address: '12 Rue',
        stock: ['KA2NP' => 2, 'KH2LC' => null],
    ))->toArray();

    // Quantity is optional per the API, so a null one emits the bare ref.
    expect($payload['STOCK'])->toBe('KA2NP:2,KH2LC');
});

test('cities are built from the id-keyed map, keeping the key as the id', function () {
    $cities = ForceLogCityDTO::fromMap([
        '17' => ['CODE' => 'NDR', 'NAME' => 'Nador', 'D_FEES' => '45', 'D_FEES_SAME_CITY' => '45'],
        '34' => ['CODE' => 'CAS', 'NAME' => 'Casablanca', 'D_FEES' => '30', 'D_FEES_SAME_CITY' => '20'],
    ]);

    expect($cities)->toHaveCount(2);
    expect($cities[1]->id)->toBe('34');
    expect($cities[1]->code)->toBe('CAS');
    expect($cities[1]->name)->toBe('Casablanca');
    // Fees arrive as strings and are cast.
    expect($cities[1]->deliveryFees)->toBe(30.0);
    expect($cities[1]->deliveryFeesSameCity)->toBe(20.0);
});
