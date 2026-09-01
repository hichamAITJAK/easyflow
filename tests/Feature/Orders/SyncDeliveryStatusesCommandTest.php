<?php

use App\Enums\Courier;
use App\Enums\OrderDeliveryStatus;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeShippedOrder(int $businessId, DeliveryAccount $account, array $overrides = []): Order
{
    return makeOrder($businessId, [
        'confirmation_status' => 'submitted_to_courier',
        'delivery_status' => 'in_transit',
        'is_delivery_active' => true,
        'delivery_account_id' => $account->id,
        'courier_tracking_number' => 'TRACK-'.uniqid(),
        ...$overrides,
    ]);
}

test('the command updates delivery_status and clears is_delivery_active once a Sendit parcel is delivered', function () {
    Http::fake([
        'app.sendit.ma/api/v1/deliveries/*' => Http::response([
            'data' => ['status' => 'DELIVERED'],
        ], 200),
    ]);

    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);
    $account = DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['token' => 'test-token']),
        'status' => 'active',
    ]);
    $order = makeShippedOrder($admin->business_id, $account);

    Artisan::call('orders:sync-delivery-statuses');

    $order->refresh();
    expect($order->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED);
    expect($order->is_delivery_active)->toBeFalse();
});

test('the command leaves an order untouched when the courier reports no new status', function () {
    Http::fake([
        'app.sendit.ma/api/v1/deliveries/*' => Http::response([
            'data' => ['status' => 'TRANSIT'],
        ], 200),
    ]);

    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);
    $account = DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['token' => 'test-token']),
        'status' => 'active',
    ]);
    $order = makeShippedOrder($admin->business_id, $account, ['delivery_status' => 'in_transit']);

    Artisan::call('orders:sync-delivery-statuses');

    $order->refresh();
    expect($order->delivery_status)->toBe(OrderDeliveryStatus::IN_TRANSIT);
    expect($order->is_delivery_active)->toBeTrue();
});

test('the command only checks orders still flagged as delivery-active', function () {
    Http::fake([
        'app.sendit.ma/api/v1/deliveries/*' => Http::response([
            'data' => ['status' => 'DELIVERED'],
        ], 200),
    ]);

    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);
    $account = DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['token' => 'test-token']),
        'status' => 'active',
    ]);
    $alreadyDelivered = makeShippedOrder($admin->business_id, $account, [
        'delivery_status' => 'delivered',
        'is_delivery_active' => false,
    ]);

    Artisan::call('orders:sync-delivery-statuses');

    Http::assertNothingSent();
    expect($alreadyDelivered->fresh()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED);
});

test('a courier failure for one order does not stop the rest of the batch from being checked', function () {
    Http::fakeSequence('app.sendit.ma/api/v1/deliveries/*')
        ->push(['message' => 'server error'], 500)
        ->push(['data' => ['status' => 'DELIVERED']], 200);

    $admin = makeBusinessUser();
    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);
    $account = DeliveryAccount::create([
        'business_id' => $admin->business_id,
        'courier_id' => $courier->id,
        'label' => 'Main account',
        'api_credentials' => json_encode(['token' => 'test-token']),
        'status' => 'active',
    ]);
    $failingOrder = makeShippedOrder($admin->business_id, $account);
    $succeedingOrder = makeShippedOrder($admin->business_id, $account);

    $exitCode = Artisan::call('orders:sync-delivery-statuses');

    expect($exitCode)->toBe(1);
    expect($failingOrder->fresh()->delivery_status)->toBe(OrderDeliveryStatus::IN_TRANSIT);
    expect($succeedingOrder->fresh()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED);
});

test('the --business option restricts the sync to that business only', function () {
    Http::fake([
        'app.sendit.ma/api/v1/deliveries/*' => Http::response([
            'data' => ['status' => 'DELIVERED'],
        ], 200),
    ]);

    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => Courier::SENDIT->value]);

    $adminA = makeBusinessUser();
    $accountA = DeliveryAccount::create([
        'business_id' => $adminA->business_id,
        'courier_id' => $courier->id,
        'label' => 'A account',
        'api_credentials' => json_encode(['token' => 'test-token']),
        'status' => 'active',
    ]);
    $orderA = makeShippedOrder($adminA->business_id, $accountA);

    $adminB = makeBusinessUser();
    $accountB = DeliveryAccount::create([
        'business_id' => $adminB->business_id,
        'courier_id' => $courier->id,
        'label' => 'B account',
        'api_credentials' => json_encode(['token' => 'test-token']),
        'status' => 'active',
    ]);
    $orderB = makeShippedOrder($adminB->business_id, $accountB);

    Artisan::call('orders:sync-delivery-statuses', ['--business' => $adminA->business_id]);

    expect($orderA->fresh()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED);
    expect($orderB->fresh()->delivery_status)->toBe(OrderDeliveryStatus::IN_TRANSIT);
});
