<?php

use App\Enums\OrderDeliveryStatus;
use App\Services\Operations\Couriers\ForceLogService;

test('pre-pickup ForceLog statuses map to null', function (string $status) {
    $service = new ForceLogService;

    expect($service->mapDeliveryStatus($status))->toBeNull();
})->with([
    'NEW_PARCEL',
    'WAITING_PICKUP',
    'Nouveau',
    'En attente de ramassage',
]);

test('ForceLog status codes map onto our delivery statuses', function (string $status, OrderDeliveryStatus $expected) {
    $service = new ForceLogService;

    expect($service->mapDeliveryStatus($status))->toBe($expected);
})->with([
    ['IN_PROGRESS', OrderDeliveryStatus::IN_TRANSIT],
    ['DELIVERED', OrderDeliveryStatus::DELIVERED],
    ['RETURNED', OrderDeliveryStatus::RETURNED_IN_TRANSIT],
    ['CANCELLED', OrderDeliveryStatus::CANCELLED_AT_COURIER],
]);

test('the French display labels map the same way as their status codes', function (string $label, OrderDeliveryStatus $expected) {
    // GetParcel returns only the French STATUS, with no STATUS_CODE, so both
    // spellings have to resolve identically.
    $service = new ForceLogService;

    expect($service->mapDeliveryStatus($label))->toBe($expected);
})->with([
    ['En cours', OrderDeliveryStatus::IN_TRANSIT],
    ['Livré', OrderDeliveryStatus::DELIVERED],
    ['Retourné', OrderDeliveryStatus::RETURNED_IN_TRANSIT],
    ['Annulé', OrderDeliveryStatus::CANCELLED_AT_COURIER],
]);

test('surrounding whitespace does not break the mapping', function () {
    $service = new ForceLogService;

    expect($service->mapDeliveryStatus(' DELIVERED '))->toBe(OrderDeliveryStatus::DELIVERED);
    expect($service->mapDeliveryStatus('Livré '))->toBe(OrderDeliveryStatus::DELIVERED);
});

test('an unrecognized ForceLog status throws', function () {
    $service = new ForceLogService;

    $service->mapDeliveryStatus('SOME_FUTURE_STATUS');
})->throws(InvalidArgumentException::class);
