<?php

use App\Enums\OrderDeliveryStatus;
use App\Services\Operations\Couriers\AmeexService;

test('pre-pickup Ameex statuses map to null', function (string $status) {
    $service = new AmeexService;

    expect($service->mapDeliveryStatus($status))->toBeNull();
})->with([
    'Nouveau Colis',
    'Attente De Ramassage',
    'Programmé',
    'NEW_PARCEL',
]);

test('in-transit Ameex statuses map to IN_TRANSIT', function (string $status) {
    $service = new AmeexService;

    expect($service->mapDeliveryStatus($status))->toBe(OrderDeliveryStatus::IN_TRANSIT);
})->with(['Ramassé', 'Expédié', 'Reçu', 'En Voyage', 'En cours']);

test('no-answer Ameex statuses map to DELIVERY_ATTEMPT_FAILED', function (string $status) {
    $service = new AmeexService;

    expect($service->mapDeliveryStatus($status))->toBe(OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED);
})->with(['Pas de réponse', 'Injoignable', 'Hors-zone']);

test('the remaining Ameex statuses map one-to-one onto our terminal statuses', function (string $status, OrderDeliveryStatus $expected) {
    $service = new AmeexService;

    expect($service->mapDeliveryStatus($status))->toBe($expected);
})->with([
    ['Mise en distribution', OrderDeliveryStatus::OUT_FOR_DELIVERY],
    ['Reporté', OrderDeliveryStatus::POSTPONED],
    ['Refusé', OrderDeliveryStatus::REFUSED],
    ['Livré', OrderDeliveryStatus::DELIVERED],
    ['Retourné', OrderDeliveryStatus::RETURNED_IN_TRANSIT],
    ['Annulé', OrderDeliveryStatus::CANCELLED_AT_COURIER],
]);

test('unaccented spellings map the same as their accented form', function () {
    // Ameex's exact accenting is unverified (the status vocabulary sits
    // behind an authenticated endpoint), so both forms are accepted.
    $service = new AmeexService;

    expect($service->mapDeliveryStatus('Livre'))->toBe(OrderDeliveryStatus::DELIVERED);
    expect($service->mapDeliveryStatus('Retourne'))->toBe(OrderDeliveryStatus::RETURNED_IN_TRANSIT);
    expect($service->mapDeliveryStatus('Refuse'))->toBe(OrderDeliveryStatus::REFUSED);
});

test('casing and surrounding whitespace do not break the mapping', function () {
    $service = new AmeexService;

    expect($service->mapDeliveryStatus('  LIVRÉ  '))->toBe(OrderDeliveryStatus::DELIVERED);
    expect($service->mapDeliveryStatus('livré'))->toBe(OrderDeliveryStatus::DELIVERED);
});

test('an unrecognized Ameex status throws', function () {
    // Deliberate: SyncDeliveryStatusesCommand logs this per order, so a
    // missing label surfaces as a fixable warning rather than a parcel
    // that quietly stops updating.
    $service = new AmeexService;

    $service->mapDeliveryStatus('Some Future Status');
})->throws(InvalidArgumentException::class);
