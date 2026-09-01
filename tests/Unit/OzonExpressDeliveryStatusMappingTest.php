<?php

use App\Enums\OrderDeliveryStatus;
use App\Services\Operations\Couriers\OzonExpressService;

test('pre-pickup and informational OzonExpress statuses map to null', function (string $status) {
    $service = new OzonExpressService;

    expect($service->mapDeliveryStatus($status))->toBeNull();
})->with(['Nouveau Colis', 'Attente De Ramassage', 'client intéressé']);

test('in-transit OzonExpress statuses map to IN_TRANSIT', function (string $status) {
    $service = new OzonExpressService;

    expect($service->mapDeliveryStatus($status))->toBe(OrderDeliveryStatus::IN_TRANSIT);
})->with(['Ramassé', 'Expédié', 'Reçu']);

test('no-answer OzonExpress statuses map to DELIVERY_ATTEMPT_FAILED', function (string $status) {
    $service = new OzonExpressService;

    expect($service->mapDeliveryStatus($status))->toBe(OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED);
})->with(['Pas de réponse + SMS', 'Pas de réponse J+2', 'Pas de réponse J+3']);

test('the remaining OzonExpress statuses map one-to-one onto our terminal statuses', function (string $status, OrderDeliveryStatus $expected) {
    $service = new OzonExpressService;

    expect($service->mapDeliveryStatus($status))->toBe($expected);
})->with([
    ['Mise en distribution', OrderDeliveryStatus::OUT_FOR_DELIVERY],
    ['Livré', OrderDeliveryStatus::DELIVERED],
    ['Retourné', OrderDeliveryStatus::RETURNED_IN_TRANSIT],
]);

test('an unrecognized OzonExpress status throws', function () {
    $service = new OzonExpressService;

    $service->mapDeliveryStatus('Some Future Status');
})->throws(InvalidArgumentException::class);

test('extractDriverInfo parses name and phone out of a real Mise en distribution comment', function () {
    $service = new OzonExpressService;

    $comment = '| Livreur: ABDELLAH BENSEBAITAI | Commentaire: &lt;b&gt;Livreur: &lt;/b&gt;ABDELLAH BENSEBAITAI &lt;br /&gt;&lt;b&gt;Téléphone: &lt;/b&gt;0760855619';

    expect($service->extractDriverInfo($comment))->toBe([
        'name' => 'ABDELLAH BENSEBAITAI',
        'phone' => '0760855619',
    ]);
});

test('extractDriverInfo returns nulls when the comment has no driver info', function () {
    $service = new OzonExpressService;

    expect($service->extractDriverInfo(''))->toBe([
        'name' => null,
        'phone' => null,
    ]);
});
