<?php

use App\Enums\Courier;
use App\Enums\DeliveryAccountStatus;
use App\Enums\OrderDeliveryStatus;
use App\Models\CourierTrackingEvent;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\Couriers\OnesstaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.onessta.partner_token' => 'partner-token',
        'services.onessta.webhook_secret' => 'webhook-secret',
        'services.onessta.client_id' => null,
        'services.onessta.api_key' => null,
    ]);
});

function onesstaCourier(): DeliveryCourrier
{
    return DeliveryCourrier::firstOrCreate(['slug' => Courier::ONESSTA->value], ['name' => 'Onessta']);
}

/**
 * @return array{0: DeliveryAccount, 1: DeliveryCourrier, 2: int}
 */
function makeOnesstaAccount(): array
{
    $admin = makeBusinessUser();
    $courier = onesstaCourier();
    $pickup = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Rabat', 'external_courrier_id' => '104']);

    $account = DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'collect_city_id' => $pickup->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['client_id' => 'client-1', 'api_key' => 'key-1']),
        'status' => 'active',
    ]);

    return [$account, $courier, $admin->business_id];
}

/**
 * @return array<string, mixed>
 */
function onesstaCities(): array
{
    return ['success' => true, 'code' => 201, 'data' => ['cities' => [
        ['id' => 97, 'name' => 'Casablanca', 'delivered_fees' => '20.00', 'refused_fees' => '10.00', 'retourned_fees' => '0.00'],
        ['id' => 104, 'name' => 'Rabat', 'delivered_fees' => '35.00', 'refused_fees' => '10.00', 'retourned_fees' => '0.00'],
    ]], 'message' => 'List of all cities'];
}

/**
 * @param  array<string, mixed>  $payload
 */
function onesstaSignature(array $payload, string $secret = 'webhook-secret'): string
{
    return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);
}

/**
 * @return array<string, mixed>
 */
function onesstaWebhook(string $code, string $status, ?string $statusSecond = null): array
{
    return [
        'event_type' => 'parcel.status_updated',
        'timestamp' => '2026-11-02T10:30:00+00:00',
        'data' => [
            'parcel' => [
                'code' => $code,
                'status' => $status,
                'status_second' => $statusSecond,
                'receiver' => 'Youssef Alami',
                'city_name' => 'Casablanca',
                'updated_at' => '2026-11-02T10:30:00+00:00',
            ],
            'additional_data' => ['old_status' => 'DISTRIBUTION', 'new_status' => $status],
        ],
    ];
}

test('connecting an Onessta account with valid keys creates an active account and syncs cities', function () {
    Http::fake(['api.onessta.com/api/v1/p/cities' => Http::response(onesstaCities())]);

    $admin = makeBusinessUser();
    $courier = onesstaCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca', 'external_courrier_id' => '97']);

    $response = $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'client_id' => 'client-1',
        'api_key' => 'key-1',
    ]);

    $account = DeliveryAccount::where('business_id', $admin->business_id)->firstOrFail();
    $response->assertRedirect(route('delivery-couriers.connected', $account));
    expect($account->status)->toBe(DeliveryAccountStatus::ACTIVE)
        ->and(json_decode($account->api_credentials, true))->toBe(['client_id' => 'client-1', 'api_key' => 'key-1'])
        ->and(DeleveryCourrierCity::where('courrier_id', $courier->id)->count())->toBe(2);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer partner-token')
        && $request->hasHeader('Client-ID', 'client-1')
        && $request->hasHeader('API-Key', 'key-1'));
});

test('connecting an Onessta account with rejected keys creates no account', function () {
    Http::fake(['api.onessta.com/*' => Http::response(['message' => 'Server Error'], 500)]);

    $admin = makeBusinessUser();
    $courier = onesstaCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca', 'external_courrier_id' => '97']);

    $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
        'client_id' => 'bad',
        'api_key' => 'bad',
    ])->assertSessionHasErrors('api_key');

    expect(DeliveryAccount::where('business_id', $admin->business_id)->exists())->toBeFalse();
});

test('connecting an Onessta account requires a Client-ID and API-Key', function () {
    $admin = makeBusinessUser();
    $courier = onesstaCourier();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca']);

    $this->actingAs($admin)->post(route('delivery-couriers.store'), [
        'label' => 'Main account',
        'courier_id' => $courier->id,
        'collect_city_id' => $city->id,
    ])->assertSessionHasErrors(['client_id', 'api_key']);
});

test('addParcel sends the order to Onessta and takes the city fees as costs', function () {
    Http::fake([
        'api.onessta.com/api/v1/p/parcels/add' => Http::response(['success' => true, 'code' => 200, 'data' => ['parcel' => [
            'id' => 8186, 'code' => 'CD54598912', 'price' => '499.00', 'status' => 'NEW_PARCEL', 'situation' => 'NOT_PAID',
        ]], 'message' => 'Parcel added successfully.']),
        'api.onessta.com/api/v1/p/cities' => Http::response(onesstaCities()),
    ]);

    [$account, $courier, $businessId] = makeOnesstaAccount();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca', 'external_courrier_id' => '97']);

    $order = Order::factory()->create([
        'business_id' => $businessId,
        'reference' => 'ORD-1042',
        'customer_name' => 'Youssef Alami',
        'customer_phone' => '0600000000',
        'customer_address' => '12 Rue Ibn Batouta',
        'total_amount' => 499,
        'parcel_note' => 'Call first',
    ]);

    $parcel = (new OnesstaService($account->load('collectCity')))->addParcel($order, $city);

    expect($parcel->trackingNumber)->toBe('CD54598912')
        ->and($parcel->deliveryCost)->toBe(20.0)
        ->and($parcel->refusedCost)->toBe(10.0)
        ->and($parcel->returnedCost)->toBe(0.0);

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/parcels/add')) {
            return false;
        }

        return $request['code'] === 'ORD-1042'
            && $request['receiver'] === 'Youssef Alami'
            && $request['phone'] === '0600000000'
            && (float) $request['price'] === 499.0
            && $request['city'] === ['id' => '97', 'name' => 'Casablanca']
            && $request['pickup_city'] === ['id' => '104', 'name' => 'Rabat']
            && $request['note'] === 'Call first'
            && $request->hasHeader('Authorization', 'Bearer partner-token');
    });
});

test('addParcel refuses a city without an Onessta id', function () {
    [$account, $courier, $businessId] = makeOnesstaAccount();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Nowhere']);
    $order = Order::factory()->create(['business_id' => $businessId]);

    (new OnesstaService($account))->addParcel($order, $city);
})->throws(InvalidArgumentException::class);

test('a rejected parcel raises a RequestException', function () {
    Http::fake(['api.onessta.com/api/v1/p/parcels/add' => Http::response([
        'success' => false, 'code' => 404, 'message' => 'There is an error, verify your parameters and try again.',
        'data' => ['code' => ['Code already used']],
    ], 404)]);

    [$account, $courier, $businessId] = makeOnesstaAccount();
    $city = DeleveryCourrierCity::create(['courrier_id' => $courier->id, 'name' => 'Casablanca', 'external_courrier_id' => '97']);
    $order = Order::factory()->create(['business_id' => $businessId]);

    (new OnesstaService($account))->addParcel($order, $city);
})->throws(RequestException::class);

test('Onessta codes and French labels map to our delivery statuses', function (string $status, ?OrderDeliveryStatus $expected) {
    expect((new OnesstaService)->mapDeliveryStatus($status))->toBe($expected);
})->with([
    ['NEW_PARCEL', null],
    ['WAITING_PICKUP', null],
    ['PICKED_UP', OrderDeliveryStatus::IN_TRANSIT],
    ['Expédié', OrderDeliveryStatus::IN_TRANSIT],
    ['DISTRIBUTION', OrderDeliveryStatus::OUT_FOR_DELIVERY],
    ['Mise en distribution', OrderDeliveryStatus::OUT_FOR_DELIVERY],
    ['PROGRAMED', OrderDeliveryStatus::POSTPONED],
    ['NOANSWER', OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED],
    ['Pas de réponse + SMS', OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED],
    ['REPLACE', OrderDeliveryStatus::CHANGED],
    ['REFUSE', OrderDeliveryStatus::REFUSED],
    ['DELIVERED', OrderDeliveryStatus::DELIVERED],
    ['Livré', OrderDeliveryStatus::DELIVERED],
    ['RETURNED', OrderDeliveryStatus::RETURNED_IN_TRANSIT],
    ['CANCELED', OrderDeliveryStatus::CANCELLED_AT_COURIER],
    ['PAID', null],
]);

test('an unknown Onessta status throws', function () {
    (new OnesstaService)->mapDeliveryStatus('SOMETHING_NEW');
})->throws(InvalidArgumentException::class);

test('the secondary status wins over a plain in-progress main status', function () {
    $service = new OnesstaService;

    expect($service->mapParcelState('IN_PROGRESS', 'PROGRAMED'))->toBe(OrderDeliveryStatus::POSTPONED)
        ->and($service->mapParcelState('IN_PROGRESS', null))->toBe(OrderDeliveryStatus::IN_TRANSIT)
        ->and($service->mapParcelState('DELIVERED', 'INT'))->toBe(OrderDeliveryStatus::DELIVERED);
});

test('tracking history comes back oldest first, and an unknown parcel has none', function () {
    Http::fake([
        'api.onessta.com/api/v1/p/parcels/tracking' => Http::sequence()
            ->push(['success' => true, 'code' => 200, 'data' => ['history' => [
                ['status' => 'DISTRIBUTION', 'name' => 'Mise en distribution', 'created_at' => '2026-11-02 16:17:19', 'new_date' => null],
                ['status' => 'PICKED_UP', 'name' => 'Ramassé', 'created_at' => '2026-11-02 16:10:42', 'new_date' => null],
            ]]])
            ->push(['success' => false, 'code' => 401, 'message' => 'Parcel with Code: NOPE not found.'], 401),
        'api.onessta.com/api/v1/p/parcels/get_by_code' => Http::response(['success' => false, 'code' => 401, 'message' => 'Parcels with Code: NOPE not found.'], 401),
    ]);

    [$account] = makeOnesstaAccount();
    $service = new OnesstaService($account);

    $steps = $service->getTrackingHistory('CD54598912');

    expect(array_column($steps, 'status'))->toBe(['Ramassé', 'Mise en distribution'])
        ->and($steps[1]['happened_at']->format('Y-m-d H:i:s'))->toBe('2026-11-02 16:17:19')
        ->and($service->getCurrentStatus('NOPE'))->toBeNull();
});

test('a signed webhook moves the order and records the step', function () {
    [$account, , $businessId] = makeOnesstaAccount();

    $order = Order::factory()->create([
        'business_id' => $businessId,
        'delivery_account_id' => $account->id,
        'courier_slug' => Courier::ONESSTA->value,
        'courier_tracking_number' => 'CD54598912',
        'delivery_status' => OrderDeliveryStatus::OUT_FOR_DELIVERY,
        'is_delivery_active' => true,
    ]);

    $payload = onesstaWebhook('CD54598912', 'DELIVERED');

    $this->postJson(route('webhooks.onessta.receive'), $payload, ['X-Signature' => onesstaSignature($payload)])
        ->assertOk()
        ->assertJson(['received' => true, 'parcel_code' => 'CD54598912']);

    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED)
        ->and(CourierTrackingEvent::withoutGlobalScopes()->where('order_id', $order->id)->value('status'))->toBe('Livré');
});

test('a webhook with a bad signature is refused and changes nothing', function () {
    [$account, , $businessId] = makeOnesstaAccount();

    $order = Order::factory()->create([
        'business_id' => $businessId,
        'delivery_account_id' => $account->id,
        'courier_slug' => Courier::ONESSTA->value,
        'courier_tracking_number' => 'CD54598912',
        'delivery_status' => OrderDeliveryStatus::OUT_FOR_DELIVERY,
        'is_delivery_active' => true,
    ]);

    $payload = onesstaWebhook('CD54598912', 'DELIVERED');

    $this->postJson(route('webhooks.onessta.receive'), $payload, ['X-Signature' => onesstaSignature($payload, 'wrong')])
        ->assertUnauthorized();

    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::OUT_FOR_DELIVERY);
});

test('webhooks are refused while no secret is configured', function () {
    config(['services.onessta.webhook_secret' => null]);

    $payload = onesstaWebhook('CD54598912', 'DELIVERED');

    $this->postJson(route('webhooks.onessta.receive'), $payload, ['X-Signature' => onesstaSignature($payload, '')])
        ->assertUnauthorized();
});

test('a webhook for a parcel we do not know is still acknowledged', function () {
    $payload = onesstaWebhook('UNKNOWN1', 'DELIVERED');

    $this->postJson(route('webhooks.onessta.receive'), $payload, ['X-Signature' => onesstaSignature($payload)])
        ->assertOk();
});

test('the city sync uses a connected account when no env keys are set', function () {
    Http::fake(['api.onessta.com/api/v1/p/cities' => Http::response(onesstaCities())]);

    [, $courier] = makeOnesstaAccount();

    $this->artisan('couriers:sync-cities', ['--courier' => Courier::ONESSTA->value])
        ->expectsOutputToContain('Onessta: synced 2 cities.')
        ->assertSuccessful();

    expect(DeleveryCourrierCity::where('courrier_id', $courier->id)->where('external_courrier_id', '97')->value('name'))->toBe('Casablanca');
});

test('with no tracking history yet, the current status comes from get_by_code', function () {
    Http::fake([
        'api.onessta.com/api/v1/p/parcels/tracking' => Http::response(['success' => true, 'code' => 200, 'data' => ['history' => []]]),
        'api.onessta.com/api/v1/p/parcels/get_by_code' => Http::response(['success' => true, 'code' => 200, 'data' => ['parcel' => [
            'ID' => 1, 'CODE' => 'JSS0192109_s', 'STATUS' => 'IN_PROGRESS', 'NOTE' => 'test',
        ]], 'message' => 'Parcel fetched successfully.']),
    ]);

    [$account] = makeOnesstaAccount();

    expect((new OnesstaService($account))->getCurrentStatus('JSS0192109_s'))->toBe('IN_PROGRESS');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/parcels/get_by_code') && $request['code'] === 'JSS0192109_s');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array{0: Order, 1: User}
 */
function makeShippedOnesstaOrder(array $overrides = []): array
{
    [$account, , $businessId] = makeOnesstaAccount();

    $order = Order::factory()->create([
        'business_id' => $businessId,
        'delivery_account_id' => $account->id,
        'courier_slug' => Courier::ONESSTA->value,
        'courier_tracking_number' => 'DFSF06570381',
        'delivery_status' => OrderDeliveryStatus::IN_TRANSIT,
        'is_delivery_active' => true,
        'customer_name' => 'Old name',
        ...$overrides,
    ]);

    return [$order, User::where('business_id', $businessId)->firstOrFail()];
}

test('changing the recipient tells Onessta first, then updates the order', function () {
    Http::fake(['api.onessta.com/api/v1/p/parcels/change_recipient' => Http::response([
        'success' => true, 'code' => 200, 'data' => ['parcel' => ['code' => 'DFSF06570381']], 'message' => 'Parcel recipient updated successfully.',
    ])]);

    [$order, $user] = makeShippedOnesstaOrder();

    $this->actingAs($user)->post(route('orders.recipient', $order), [
        'customer_name' => 'Nouveau destinataire',
        'customer_phone' => '06 12 34 56 78',
        'customer_address' => 'Nouvelle adresse',
        'parcel_note' => 'Appeler avant',
    ])->assertRedirect();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.onessta.com/api/v1/p/parcels/change_recipient'
        && $request['code'] === 'DFSF06570381'
        && $request['receiver'] === 'Nouveau destinataire'
        && $request['phone'] === '0612345678'
        && $request['address'] === 'Nouvelle adresse'
        && $request['note'] === 'Appeler avant');

    $order = $order->fresh()->makeVisible(['customer_name', 'customer_address']);
    expect($order->customer_name)->toBe('Nouveau destinataire')
        ->and($order->customer_address)->toBe('Nouvelle adresse')
        ->and($order->parcel_note)->toBe('Appeler avant');
});

test('when Onessta refuses the change, the order keeps its recipient', function () {
    Http::fake(['api.onessta.com/api/v1/p/parcels/change_recipient' => Http::response([
        'success' => false, 'code' => 409, 'message' => 'Parcel recipient cannot be changed in its current state.',
    ], 409)]);

    [$order, $user] = makeShippedOnesstaOrder();

    $this->actingAs($user)->post(route('orders.recipient', $order), [
        'customer_name' => 'Nouveau destinataire',
        'customer_phone' => '0612345678',
        'customer_address' => 'Nouvelle adresse',
    ])->assertRedirect();

    expect($order->fresh()->makeVisible('customer_name')->customer_name)->toBe('Old name');
});

test('the recipient can only change on an Onessta parcel still on its way', function (array $overrides) {
    Http::fake();

    [$order, $user] = makeShippedOnesstaOrder($overrides);

    $this->actingAs($user)->post(route('orders.recipient', $order), [
        'customer_name' => 'Nouveau destinataire',
        'customer_phone' => '0612345678',
        'customer_address' => 'Nouvelle adresse',
    ])->assertForbidden();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'onessta'));
})->with([
    'refused' => [['delivery_status' => OrderDeliveryStatus::REFUSED]],
    'delivered' => [['delivery_status' => OrderDeliveryStatus::DELIVERED, 'is_delivery_active' => false]],
    'another courier' => [['courier_slug' => Courier::SENDIT->value]],
]);

test('changing the recipient checks Onessta limits before calling it', function () {
    Http::fake();

    [$order, $user] = makeShippedOnesstaOrder();

    $this->actingAs($user)->post(route('orders.recipient', $order), [
        'customer_name' => str_repeat('a', 31),
        'customer_phone' => '06123',
        'customer_address' => 'Nouvelle adresse',
    ])->assertSessionHasErrors(['customer_name', 'customer_phone']);

    Http::assertNothingSent();
});
