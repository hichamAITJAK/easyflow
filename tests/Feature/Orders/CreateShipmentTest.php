<?php

use App\Enums\Courier;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeDeliveryAccount(int $businessId, array $overrides = []): DeliveryAccount
{
    $courier = DeliveryCourrier::create([
        'name' => 'Sendit',
        'slug' => Courier::SENDIT->value,
    ]);

    $collectCity = makeCourierCity($courier->id, ['name' => 'Pickup city', 'external_courrier_id' => '99']);

    return DeliveryAccount::create([
        'business_id' => $businessId,
        'courier_id' => $courier->id,
        'collect_city_id' => $collectCity->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['token' => 'test-token']),
        'status' => 'active',
        ...$overrides,
    ]);
}

function makeCourierCity(int $courierId, array $overrides = []): DeleveryCourrierCity
{
    return DeleveryCourrierCity::create([
        'courrier_id' => $courierId,
        'name' => 'Casablanca',
        'external_courrier_id' => '12',
        ...$overrides,
    ]);
}

/**
 * Fake a successful Sendit createParcel response so tests that dispatch a
 * real shipment don't hit the network — code/fee mirror what the courier
 * would actually return.
 */
function fakeSenditParcelCreation(string $code = 'SEND-123', ?float $fee = 15.5): void
{
    Http::fake([
        'app.sendit.ma/api/v1/deliveries' => Http::response([
            'data' => [
                'code' => $code,
                'fee' => $fee,
            ],
        ], 201),
    ]);
}

function makeOzonExpressDeliveryAccount(int $businessId, array $overrides = []): DeliveryAccount
{
    $courier = DeliveryCourrier::create([
        'name' => 'OzonExpress',
        'slug' => Courier::OZONEXPRESS->value,
    ]);

    return DeliveryAccount::create([
        'business_id' => $businessId,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['ozon_id' => 'test-id', 'api_key' => 'test-key']),
        'status' => 'active',
        ...$overrides,
    ]);
}

function makeColiixDeliveryAccount(int $businessId, array $overrides = []): DeliveryAccount
{
    $courier = DeliveryCourrier::create([
        'name' => 'Coliix',
        'slug' => Courier::COLIIX->value,
    ]);

    return DeliveryAccount::create([
        'business_id' => $businessId,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['client_id' => 'test-client-id', 'token' => 'test-token']),
        'status' => 'active',
        ...$overrides,
    ]);
}

/**
 * Fake a successful Coliix "add" response — Coliix returns only a tracking
 * code and status message, no cost fields.
 */
function fakeColiixParcelCreation(string $tracking = 'COL-321'): void
{
    Http::fake([
        'my.coliix.com/casa/seller/api-parcels' => Http::response([
            'status' => 200,
            'msg' => 'ajouté avec succès',
            'tracking' => $tracking,
        ], 200),
    ]);
}

/**
 * Fake a successful OzonExpress createParcel response, matching the real
 * (nested, ADD-PARCEL-wrapped) shape the API actually returns.
 */
function fakeOzonExpressParcelCreation(
    string $trackingNumber = 'OZ-456',
    ?float $deliveredPrice = 35.0,
    ?float $returnedPrice = 0.0,
    ?float $refusedPrice = 10.0,
): void {
    Http::fake([
        'api.ozonexpress.ma/customers/*/add-parcel' => Http::response([
            'ADD-PARCEL' => [
                'CUSTOMER' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valid Customer'],
                'RESULT' => 'SUCCESS',
                'MESSAGE' => 'New Parcel Added',
                'NEW-PARCEL' => [
                    'TRACKING-NUMBER' => $trackingNumber,
                    'RECEIVER' => 'Jane Doe',
                    'PHONE' => '0666666666',
                    'CITY_ID' => 37,
                    'ADDRESS' => 'address, addres',
                    'PRICE' => 10,
                    'NOTE' => '',
                    'DELIVERED-PRICE' => $deliveredPrice,
                    'RETURNED-PRICE' => $returnedPrice,
                    'REFUSED-PRICE' => $refusedPrice,
                ],
            ],
        ], 200),
    ]);
}

/**
 * Fake an OzonExpress createParcel response that fails at the API level
 * (HTTP 200, but ADD-PARCEL.RESULT !== "SUCCESS") — e.g. an invalid city or
 * a missing required field. OzonExpress uses "ERROR" for the latter, per a
 * real sample response, but any non-"SUCCESS" value must be treated the
 * same way, so the result value itself is parameterized here to cover both.
 */
function fakeOzonExpressParcelCreationFailure(string $result = 'ERROR', string $message = 'Some fields Empty'): void
{
    Http::fake([
        'api.ozonexpress.ma/customers/*/add-parcel' => Http::response([
            'ADD-PARCEL' => [
                'CUSTOMER' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valid Customer'],
                'RESULT' => $result,
                'MESSAGE' => $message,
            ],
        ], 200),
    ]);
}

test('creating a shipment for a confirmed order transitions it to submitted_to_courier', function () {
    fakeSenditParcelCreation(code: 'SEND-999', fee: 22.0);

    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $response = $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Updated Name',
        'customer_phone' => '+212611111111',
        'customer_address' => 'New address',
        'total_amount' => 250,
    ]);

    $response->assertRedirect(route('orders.index'));

    $order->refresh();
    expect($order->confirmation_status)->toBe(OrderConfirmationStatus::SUBMITTED_TO_COURIER);
    expect($order->delivery_status)->toBe(OrderDeliveryStatus::AWAITING_PICKUP);
    expect($order->delivery_account_id)->toBe($account->id);
    expect($order->customer_city)->toBe($city->name);
    expect((float) $order->total_amount)->toBe(250.0);
    expect($order->courier_tracking_number)->toBe('SEND-999');
    expect((float) $order->delivery_cost)->toBe(22.0);
});

test('a shipment cannot be created for an order that is not confirmed', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'new']);

    $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ])->assertStatus(422);

    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::NEW);
});

test('a city belonging to a different courier is rejected', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    $otherCourier = DeliveryCourrier::create(['name' => 'OzonExpress', 'slug' => 'ozon-'.uniqid()]);
    $otherCourierCity = makeCourierCity($otherCourier->id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $otherCourierCity->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ])->assertStatus(404);
});

test('a confirmation agent cannot create a shipment for an order not assigned to them', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'confirmed',
        'assigned_agent_id' => null,
    ]);

    $this->actingAs($agent)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ])->assertForbidden();
});

test('a confirmation agent can create a shipment for their own confirmed order', function () {
    fakeSenditParcelCreation();

    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'confirmed',
        'assigned_agent_id' => $agent->id,
    ]);

    $this->actingAs($agent)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ])->assertRedirect(route('orders.index'));

    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::SUBMITTED_TO_COURIER);
});

test('a delivery account belonging to another business is rejected', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();
    $otherAccount = makeDeliveryAccount($otherAdmin->business_id);
    $city = makeCourierCity($otherAccount->courier_id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $otherAccount->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ])->assertSessionHasErrors('delivery_account_id');
});

test('the cities endpoint lists cities for the account\'s courier', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    makeCourierCity($account->courier_id, ['name' => 'Rabat']);
    makeCourierCity($account->courier_id, ['name' => 'Fes']);

    $response = $this->actingAs($admin)->getJson(route('orders.delivery-accounts.cities', $account));

    $response->assertOk();
    // makeDeliveryAccount() already seeds one city (the account's pickup
    // city) under this courier, plus the two created here.
    expect($response->json('cities'))->toHaveCount(3);
});

test('the cities endpoint 404s for a delivery account belonging to another business', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();
    $otherAccount = makeDeliveryAccount($otherAdmin->business_id);

    // DeliveryAccount's BusinessScope excludes the other business's account
    // from route-model binding entirely, so this 404s before the
    // controller's own abort_unless check ever runs — defense in depth.
    $this->actingAs($admin)
        ->getJson(route('orders.delivery-accounts.cities', $otherAccount))
        ->assertNotFound();
});

test('the order\'s own reference is sent to the courier as the outbound reference', function () {
    fakeSenditParcelCreation();

    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'confirmed',
        'reference' => 'ORD-ABC123',
    ]);

    $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ])->assertRedirect(route('orders.index'));

    Http::assertSent(fn ($request) => $request['reference'] === 'ORD-ABC123');
});

test('courier_tracking_number stays null until the courier assigns one, then the response overwrites it', function () {
    fakeSenditParcelCreation(code: 'SEND-777', fee: 33.25);

    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'confirmed',
        'reference' => 'ORD-ABC123',
    ]);

    expect($order->courier_tracking_number)->toBeNull();

    $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ]);

    $order->refresh();
    expect($order->courier_tracking_number)->toBe('SEND-777');
    expect((float) $order->delivery_cost)->toBe(33.25);
});

test('an OzonExpress shipment captures the delivered/returned/refused prices from the nested response', function () {
    fakeOzonExpressParcelCreation(
        trackingNumber: 'OZ-999',
        deliveredPrice: 35.0,
        returnedPrice: 0.0,
        refusedPrice: 10.0,
    );

    $admin = makeBusinessUser();
    $account = makeOzonExpressDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ])->assertRedirect(route('orders.index'));

    $order->refresh();
    expect($order->courier_tracking_number)->toBe('OZ-999');
    expect((float) $order->delivery_cost)->toBe(35.0);
    expect((float) $order->returned_cost)->toBe(0.0);
    expect((float) $order->refused_cost)->toBe(10.0);
});

test('a city with no external_courrier_id is rejected before calling the courier', function () {
    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id, ['external_courrier_id' => null]);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    Http::fake();

    $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ])->assertStatus(422);

    Http::assertNothingSent();
    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
});

test('a Coliix shipment succeeds for a city with no external_courrier_id, since Coliix ships by city name', function () {
    fakeColiixParcelCreation(tracking: 'COL-999');

    $admin = makeBusinessUser();
    $account = makeColiixDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id, ['name' => 'Marrakech', 'external_courrier_id' => null]);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $response = $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ]);

    $response->assertRedirect(route('orders.index'));

    $order->refresh();
    expect($order->confirmation_status)->toBe(OrderConfirmationStatus::SUBMITTED_TO_COURIER);
    expect($order->courier_tracking_number)->toBe('COL-999');
    expect($order->customer_city)->toBe('Marrakech');

    Http::assertSent(fn ($request) => $request['ville'] === 'Marrakech');
});

test('a failed courier request does not transition the order and shows an error toast', function () {
    Http::fake([
        'app.sendit.ma/api/v1/deliveries' => Http::response(['message' => 'Invalid district'], 422),
    ]);

    $admin = makeBusinessUser();
    $account = makeDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $response = $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ]);

    $response->assertRedirect(route('orders.index'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
});

test('an OzonExpress API-level failure (HTTP 200, RESULT: ERROR) does not transition the order and shows an error toast', function () {
    fakeOzonExpressParcelCreationFailure(result: 'ERROR', message: 'Some fields Empty');

    $admin = makeBusinessUser();
    $account = makeOzonExpressDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $response = $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ]);

    $response->assertRedirect(route('orders.index'));
    $response->assertInertiaFlash('toast.type', 'error');
    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
    expect($order->fresh()->courier_tracking_number)->toBeNull();
});

test('any OzonExpress RESULT value other than SUCCESS is treated as a failure', function () {
    fakeOzonExpressParcelCreationFailure(result: 'FAILED', message: 'Invalid city');

    $admin = makeBusinessUser();
    $account = makeOzonExpressDeliveryAccount($admin->business_id);
    $city = makeCourierCity($account->courier_id);
    $order = makeOrder($admin->business_id, ['confirmation_status' => 'confirmed']);

    $response = $this->actingAs($admin)->post(route('orders.shipment', $order), [
        'delivery_account_id' => $account->id,
        'city_id' => $city->id,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ]);

    $response->assertInertiaFlash('toast.type', 'error');
    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
});
