<?php

use App\Enums\Courier;
use App\Enums\OrderDeliveryStatus;
use App\Models\CourierTrackingEvent;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** A trimmed copy of a real Coliix track() answer. */
function coliixTrackAnswer(array $statuses): array
{
    return [
        'status' => true,
        'tracking' => 'ODA1026134228RO',
        'msg' => array_map(fn (string $status, int $i) => [
            'code' => 'ODA1026134228RO',
            'etat' => $status === 'Livré ' ? 'Payé' : 'Non Payé',
            'status' => $status,
            'time' => sprintf('2026-10-1%d 15:1%d :22', 5 + intdiv($i, 4), $i),
        ], $statuses, array_keys($statuses)),
    ];
}

function coliixAccount(int $businessId): DeliveryAccount
{
    $courier = DeliveryCourrier::create(['name' => 'Coliix', 'slug' => Courier::COLIIX->value]);

    return DeliveryAccount::create([
        'business_id' => $businessId,
        'courier_id' => $courier->id,
        'label' => 'Coliix',
        'api_credentials' => json_encode(['token' => 'test-token']),
        'status' => 'active',
    ]);
}

$fullJourney = ['Nouveau Colis ', 'Attente De Ramassage ', 'Ramassé ', 'Expédié ', 'Reçu ', 'Mise en distribution ', 'Pas de réponse  ', 'Client intéressé ', 'Livré '];

test('an order with a tracking number but no status takes Coliix\'s status and keeps its whole journey', function () use ($fullJourney) {
    Http::fake(['my.coliix.com/*' => Http::response(coliixTrackAnswer($fullJourney))]);
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'delivery_account_id' => coliixAccount($admin->business_id)->id,
        'courier_tracking_number' => 'ODA1026134228RO',
    ]);

    $this->artisan('orders:sync-delivery-statuses')->assertSuccessful();

    $order->refresh();
    expect($order->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED)
        ->and($order->is_delivery_active)->toBeFalse();

    $steps = CourierTrackingEvent::withoutGlobalScopes()->where('order_id', $order->id)->orderBy('id')->get();
    expect($steps)->toHaveCount(9)
        ->and($steps->first()->status)->toBe('Nouveau Colis')
        ->and($steps->first()->happened_at->format('Y-m-d H:i:s'))->toBe('2026-10-15 15:10:22')
        ->and($steps->last()->status)->toBe('Livré')
        ->and($steps->last()->payment_state)->toBe('Payé')
        ->and($steps->last()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED);

    // The detail shows it, oldest first.
    $this->actingAs($admin)->getJson(route('orders.show', $order))
        ->assertJsonCount(9, 'order.courier_tracking_events')
        ->assertJsonPath('order.courier_tracking_events.8.status', 'Livré');
});

test('a later sync only adds the new steps', function () use ($fullJourney) {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'delivery_account_id' => coliixAccount($admin->business_id)->id,
        'courier_tracking_number' => 'ODA1026134228RO',
    ]);

    Http::fake(['my.coliix.com/*' => Http::sequence()
        ->push(coliixTrackAnswer(array_slice($fullJourney, 0, 6)))
        ->push(coliixTrackAnswer($fullJourney))]);

    $this->artisan('orders:sync-delivery-statuses');
    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::OUT_FOR_DELIVERY);

    $this->artisan('orders:sync-delivery-statuses');
    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED)
        ->and(CourierTrackingEvent::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(9);
});

test('an order with no status skips a trailing note and takes the last real status', function () {
    Http::fake(['my.coliix.com/*' => Http::response(coliixTrackAnswer(['Ramassé ', 'Pas de réponse  ', 'Client intéressé ']))]);
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'delivery_account_id' => coliixAccount($admin->business_id)->id,
        'courier_tracking_number' => 'ODA1026134228RO',
    ]);

    $this->artisan('orders:sync-delivery-statuses');

    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED);
});

test('a parcel Coliix has only registered so far puts the order on awaiting pickup', function () {
    Http::fake(['my.coliix.com/*' => Http::response(coliixTrackAnswer(['Nouveau Colis ']))]);
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'delivery_account_id' => coliixAccount($admin->business_id)->id,
        'courier_tracking_number' => 'ODA1026134228RO',
    ]);

    $this->artisan('orders:sync-delivery-statuses');

    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::AWAITING_PICKUP)
        ->and($order->fresh()->is_delivery_active)->toBeTrue();
});

test('--history fills in a finished parcel\'s journey without touching its status', function () {
    Http::fake(['my.coliix.com/*' => Http::response(coliixTrackAnswer(['Ramassé ', 'Retourné ']))]);
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'delivery_account_id' => coliixAccount($admin->business_id)->id,
        'courier_tracking_number' => 'ODA1026134228RO',
        // Back in the warehouse: further along than Coliix's "Retourné".
        'delivery_status' => OrderDeliveryStatus::RETURN_RECEIVED,
        'is_delivery_active' => false,
    ]);

    // The hourly run leaves finished parcels alone.
    $this->artisan('orders:sync-delivery-statuses');
    expect(CourierTrackingEvent::withoutGlobalScopes()->count())->toBe(0);

    $this->artisan('orders:sync-delivery-statuses', ['--history' => true])->assertSuccessful();

    expect(CourierTrackingEvent::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(2)
        ->and($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::RETURN_RECEIVED);
});
