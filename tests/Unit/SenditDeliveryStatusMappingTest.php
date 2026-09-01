<?php

use App\Enums\OrderDeliveryStatus;
use App\Services\Operations\Couriers\SenditService;

test('pre-pickup Sendit statuses map to null, not a status we would overwrite ourselves', function (string $senditStatus) {
    $service = new SenditService;

    expect($service->mapDeliveryStatus($senditStatus))->toBeNull();
})->with(['PENDING', 'TO_PREPARE', 'NEW_DESTINATION', 'TOPICKUP']);

test('in-transit Sendit statuses map to IN_TRANSIT', function (string $senditStatus) {
    $service = new SenditService;

    expect($service->mapDeliveryStatus($senditStatus))->toBe(OrderDeliveryStatus::IN_TRANSIT);
})->with(['PICKEDUP', 'WAREHOUSE', 'TRANSIT', 'DISTRIBUTED', 'DELIVERING']);

test('the remaining Sendit statuses map one-to-one onto our terminal/exception statuses', function (string $senditStatus, OrderDeliveryStatus $expected) {
    $service = new SenditService;

    expect($service->mapDeliveryStatus($senditStatus))->toBe($expected);
})->with([
    ['UNREACHABLE', OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED],
    ['POSTPONED', OrderDeliveryStatus::POSTPONED],
    ['CANCELED', OrderDeliveryStatus::CANCELLED_AT_COURIER],
    ['REJECTED', OrderDeliveryStatus::REFUSED],
    ['DELIVERED', OrderDeliveryStatus::DELIVERED],
]);

test('an unrecognized Sendit status throws', function () {
    $service = new SenditService;

    $service->mapDeliveryStatus('SOME_FUTURE_STATUS');
})->throws(InvalidArgumentException::class);
