<?php

use App\Enums\Courier;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// actingAsConfirmationAgent() comes from MobileLeadsTest — Pest loads every
// test file into one scope, so redeclaring it here is a fatal error.

function makeMobileCourierCity(int $courierId, array $overrides = []): DeleveryCourrierCity
{
    return DeleveryCourrierCity::create([
        'courrier_id' => $courierId,
        'name' => 'Casablanca',
        'external_courrier_id' => '12',
        ...$overrides,
    ]);
}

function makeMobileDeliveryAccount(int $businessId, array $overrides = []): DeliveryAccount
{
    $courier = DeliveryCourrier::create([
        'name' => 'Sendit',
        'slug' => Courier::SENDIT->value,
    ]);

    $collectCity = makeMobileCourierCity($courier->id, ['name' => 'Pickup city', 'external_courrier_id' => '99']);

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

/**
 * Fake a successful Sendit createParcel response so tests that dispatch a
 * real shipment don't hit the network.
 */
function fakeMobileSenditParcelCreation(string $code = 'SEND-123'): void
{
    Http::fake([
        'app.sendit.ma/api/v1/deliveries' => Http::response([
            'data' => ['code' => $code, 'fee' => 15.5],
        ], 201),
    ]);
}

function shipmentPayload(int $accountId, int $cityId, array $overrides = []): array
{
    return [
        'delivery_account_id' => $accountId,
        'city_id' => $cityId,
        'customer_name' => 'Nadia Amrani',
        'customer_phone' => '+212600000000',
        'customer_address' => '12 Rue Hassan II',
        'total_amount' => 249.00,
        ...$overrides,
    ];
}

test('the delivery accounts list returns only the business\'s own active accounts', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherUser = makeBusinessUser();

    makeMobileDeliveryAccount($user->business_id, ['label' => 'Mine']);
    makeMobileDeliveryAccount($user->business_id, ['label' => 'Unverified', 'status' => 'unverified']);
    makeMobileDeliveryAccount($otherUser->business_id, ['label' => 'Theirs']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.delivery-accounts'));

    $response->assertOk();
    $accounts = $response->json('delivery_accounts');

    expect($accounts)->toHaveCount(1);
    expect($accounts[0]['label'])->toBe('Mine');
    expect($accounts[0]['courier'])->toBe('Sendit');
});

test('the cities list returns the account courier\'s own cities', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $account = makeMobileDeliveryAccount($user->business_id);
    makeMobileCourierCity($account->courier_id, ['name' => 'Rabat', 'external_courrier_id' => '13']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.delivery-accounts.cities', $account->id));

    $response->assertOk();
    // Pickup city + Rabat, alphabetically.
    expect(collect($response->json('cities'))->pluck('name')->all())->toBe(['Pickup city', 'Rabat']);
});

test('the cities list rejects an account belonging to another business', function () {
    [, $token] = actingAsConfirmationAgent();
    $otherUser = makeBusinessUser();
    $account = makeMobileDeliveryAccount($otherUser->business_id);

    // 404 rather than 403: DeliveryAccount carries a global BusinessScope,
    // so route-model binding can't resolve another tenant's account at all
    // — the controller's own ownership check never even runs. Not existing
    // is a stronger answer than not allowed, and leaks less.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.delivery-accounts.cities', $account->id))
        ->assertNotFound();
});

test('shipping a confirmed lead registers the parcel and moves it to submitted_to_courier', function () {
    fakeMobileSenditParcelCreation();
    [$user, $token] = actingAsConfirmationAgent();
    $account = makeMobileDeliveryAccount($user->business_id);
    $city = makeMobileCourierCity($account->courier_id, ['name' => 'Fes', 'external_courrier_id' => '14']);
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'confirmation_status' => OrderConfirmationStatus::CONFIRMED,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.leads.shipment', $order->id), shipmentPayload($account->id, $city->id));

    $response->assertOk();

    $order->refresh();
    expect($order->confirmation_status)->toBe(OrderConfirmationStatus::SUBMITTED_TO_COURIER);
    expect($order->delivery_status)->toBe(OrderDeliveryStatus::AWAITING_PICKUP);
    expect($order->courier_tracking_number)->toBe('SEND-123');
    expect($order->customer_city)->toBe('Fes');
    expect($order->shipped_at)->not->toBeNull();
});

test('the parcel handling flags sent at shipment reach the order', function () {
    fakeMobileSenditParcelCreation();
    [$user, $token] = actingAsConfirmationAgent();
    $account = makeMobileDeliveryAccount($user->business_id);
    $city = makeMobileCourierCity($account->courier_id, ['name' => 'Fes', 'external_courrier_id' => '14']);
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'confirmation_status' => OrderConfirmationStatus::CONFIRMED,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.leads.shipment', $order->id), shipmentPayload($account->id, $city->id, [
            'parcel_fragile' => true,
            'parcel_open' => true,
            'parcel_replace' => false,
            'parcel_note' => 'Handle with care',
        ]))
        ->assertOk();

    $order->refresh();
    expect($order->parcel_fragile)->toBeTrue();
    expect($order->parcel_open)->toBeTrue();
    expect($order->parcel_note)->toBe('Handle with care');
});

test('shipping a lead that is not confirmed is rejected', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $account = makeMobileDeliveryAccount($user->business_id);
    $city = makeMobileCourierCity($account->courier_id, ['name' => 'Fes', 'external_courrier_id' => '14']);
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.leads.shipment', $order->id), shipmentPayload($account->id, $city->id))
        ->assertStatus(422);

    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::NEW);
});

test('shipping a lead assigned to another agent is forbidden', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherAgent = makeBusinessUser(['business_id' => $user->business_id, 'role' => 'confirmation_agent']);
    $account = makeMobileDeliveryAccount($user->business_id);
    $city = makeMobileCourierCity($account->courier_id, ['name' => 'Fes', 'external_courrier_id' => '14']);
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $otherAgent->id,
        'confirmation_status' => OrderConfirmationStatus::CONFIRMED,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.leads.shipment', $order->id), shipmentPayload($account->id, $city->id))
        ->assertForbidden();
});

test('a courier API failure is reported as 502, not a validation error', function () {
    Http::fake(['app.sendit.ma/api/v1/deliveries' => Http::response(['message' => 'upstream down'], 500)]);
    [$user, $token] = actingAsConfirmationAgent();
    $account = makeMobileDeliveryAccount($user->business_id);
    $city = makeMobileCourierCity($account->courier_id, ['name' => 'Fes', 'external_courrier_id' => '14']);
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'confirmation_status' => OrderConfirmationStatus::CONFIRMED,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.leads.shipment', $order->id), shipmentPayload($account->id, $city->id))
        ->assertStatus(502);

    // Nothing shipped, so the order must not claim it was.
    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
});

test('a test order can never be shipped to a real courier', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $account = makeMobileDeliveryAccount($user->business_id);
    $city = makeMobileCourierCity($account->courier_id, ['name' => 'Fes', 'external_courrier_id' => '14']);
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'confirmation_status' => OrderConfirmationStatus::CONFIRMED,
        'is_test' => true,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.leads.shipment', $order->id), shipmentPayload($account->id, $city->id))
        ->assertStatus(422);
});

test('shipping requires a delivery account and a city', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'confirmation_status' => OrderConfirmationStatus::CONFIRMED,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.leads.shipment', $order->id), [
            'customer_name' => 'Nadia Amrani',
            'customer_phone' => '+212600000000',
            'customer_address' => '12 Rue Hassan II',
            'total_amount' => 249.00,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['delivery_account_id', 'city_id']);
});

test('shipping with another business\'s delivery account is rejected', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherUser = makeBusinessUser();
    $foreignAccount = makeMobileDeliveryAccount($otherUser->business_id);
    $city = makeMobileCourierCity($foreignAccount->courier_id, ['name' => 'Fes', 'external_courrier_id' => '14']);
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'confirmation_status' => OrderConfirmationStatus::CONFIRMED,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.leads.shipment', $order->id), shipmentPayload($foreignAccount->id, $city->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('delivery_account_id');
});
