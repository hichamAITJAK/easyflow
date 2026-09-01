<?php

use App\Enums\OrderDeliveryStatus;
use App\Services\Operations\Couriers\ColiixService;

test('pre-pickup and informational Coliix statuses map to null', function (string $status) {
    $service = new ColiixService;

    expect($service->mapDeliveryStatus($status))->toBeNull();
})->with([
    'Nouveau Colis', 'Attente De Ramassage', 'Programmé',
    'Client intéressé', 'Client pas intéressé', 'CLIENT PAS COMMENDE',
    'Confirmer par le livreur', 'En cours', 'Boite Vocal',
    'Relancé nouveau client',
]);

test('a trailing space, as seen on real track() history entries, does not break the mapping', function () {
    $service = new ColiixService;

    expect($service->mapDeliveryStatus('Nouveau Colis '))->toBeNull();
    expect($service->mapDeliveryStatus('Livré '))->toBe(OrderDeliveryStatus::DELIVERED);
});

test('in-transit Coliix statuses map to IN_TRANSIT', function (string $status) {
    $service = new ColiixService;

    expect($service->mapDeliveryStatus($status))->toBe(OrderDeliveryStatus::IN_TRANSIT);
})->with(['Ramassé', 'Expédié', 'Reçu', 'En Voyage']);

test('no-answer / unreachable Coliix statuses map to DELIVERY_ATTEMPT_FAILED', function (string $status) {
    $service = new ColiixService;

    expect($service->mapDeliveryStatus($status))->toBe(OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED);
})->with([
    'Pas de réponse', 'Deuxième Appel Pas Réponse', 'Troisième Appel Pas Réponse',
    'Numero_Erroné', 'Injoignable', 'Hors-zone', 'Attende de relancer',
]);

test('the remaining Coliix statuses map one-to-one onto our terminal statuses', function (string $status, OrderDeliveryStatus $expected) {
    $service = new ColiixService;

    expect($service->mapDeliveryStatus($status))->toBe($expected);
})->with([
    ['Mise en distribution', OrderDeliveryStatus::OUT_FOR_DELIVERY],
    ['Reporté', OrderDeliveryStatus::POSTPONED],
    ['Refusé', OrderDeliveryStatus::REFUSED],
    ['Livré', OrderDeliveryStatus::DELIVERED],
    ['Retourné', OrderDeliveryStatus::RETURNED_IN_TRANSIT],
    ['Demande de Retour', OrderDeliveryStatus::RETURNED_IN_TRANSIT],
    ['En retour par AMANA', OrderDeliveryStatus::RETURNED_IN_TRANSIT],
    ['Annulé', OrderDeliveryStatus::CANCELLED_AT_COURIER],
    ['Annulé par Vendeur', OrderDeliveryStatus::CANCELLED_AT_COURIER],
]);

test('an unrecognized Coliix status throws', function () {
    $service = new ColiixService;

    $service->mapDeliveryStatus('Some Future Status');
})->throws(InvalidArgumentException::class);
