<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Events\Order\OrderCreated;
use App\Models\Customer;
use App\Models\CustomerBlacklistEntry;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deliverOrder(OrderService $service, $order, $admin)
{
    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);
    $service->updateDeliveryStatus($order->fresh(), OrderDeliveryStatus::DELIVERED, $admin);
}

function returnOrder(OrderService $service, $order, $admin)
{
    $service->updateDeliveryStatus($order->fresh(), OrderDeliveryStatus::RETURN_RECEIVED, $admin);
}

test('a new order creates a customer record keyed by phone hash', function () {
    $admin = makeBusinessUser();

    $order = makeOrder($admin->business_id, [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '0600000000',
        'customer_phone_hash' => hash('sha256', '0600000000'),
        'customer_address' => '123 Main St',
        'customer_city' => 'Casablanca',
    ]);

    app('events')->dispatch(new OrderCreated($order));

    $customer = Customer::where('business_id', $admin->business_id)->firstOrFail();
    expect($customer->phone_hash)->toBe($order->customer_phone_hash);
    expect($customer->orders_count)->toBe(1);
});

test('a repeat order for the same phone number increments orders_count on the existing customer', function () {
    $admin = makeBusinessUser();
    $phoneHash = hash('sha256', '0600000000');

    foreach ([1, 2] as $i) {
        $order = makeOrder($admin->business_id, [
            'customer_phone' => '0600000000',
            'customer_phone_hash' => $phoneHash,
        ]);
        app('events')->dispatch(new OrderCreated($order));
    }

    $customers = Customer::where('business_id', $admin->business_id)->where('phone_hash', $phoneHash)->get();
    expect($customers)->toHaveCount(1);
    expect($customers->first()->orders_count)->toBe(2);
});

test('a test order never creates or updates a customer record', function () {
    $admin = makeBusinessUser();

    $order = makeOrder($admin->business_id, [
        'customer_phone' => '0600000000',
        'customer_phone_hash' => hash('sha256', '0600000000'),
        'is_test' => true,
    ]);
    app('events')->dispatch(new OrderCreated($order));

    expect(Customer::where('business_id', $admin->business_id)->count())->toBe(0);
});

test('a customer becomes a best customer after 3 delivered orders with no returns', function () {
    $admin = makeBusinessUser();
    $service = app(OrderService::class);
    $phoneHash = hash('sha256', '0600000000');

    foreach (range(1, 3) as $i) {
        $order = makeOrder($admin->business_id, [
            'customer_phone' => '0600000000',
            'customer_phone_hash' => $phoneHash,
            'confirmation_status' => OrderConfirmationStatus::NEW,
        ]);
        app('events')->dispatch(new OrderCreated($order));
        deliverOrder($service, $order, $admin);
    }

    $customer = Customer::where('business_id', $admin->business_id)->where('phone_hash', $phoneHash)->firstOrFail();
    expect($customer->delivered_orders_count)->toBe(3);
    expect($customer->is_best_customer)->toBeTrue();
});

test('a customer with fewer than 3 delivered orders is not a best customer', function () {
    $admin = makeBusinessUser();
    $service = app(OrderService::class);
    $phoneHash = hash('sha256', '0600000000');

    $order = makeOrder($admin->business_id, [
        'customer_phone' => '0600000000',
        'customer_phone_hash' => $phoneHash,
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);
    app('events')->dispatch(new OrderCreated($order));
    deliverOrder($service, $order, $admin);

    $customer = Customer::where('business_id', $admin->business_id)->where('phone_hash', $phoneHash)->firstOrFail();
    expect($customer->delivered_orders_count)->toBe(1);
    expect($customer->is_best_customer)->toBeFalse();
});

test('a physically confirmed return disqualifies a customer from best-customer status', function () {
    $admin = makeBusinessUser();
    $service = app(OrderService::class);
    $phoneHash = hash('sha256', '0600000000');

    foreach (range(1, 3) as $i) {
        $order = makeOrder($admin->business_id, [
            'customer_phone' => '0600000000',
            'customer_phone_hash' => $phoneHash,
            'confirmation_status' => OrderConfirmationStatus::NEW,
        ]);
        app('events')->dispatch(new OrderCreated($order));
        deliverOrder($service, $order, $admin);
    }

    $customer = Customer::where('business_id', $admin->business_id)->where('phone_hash', $phoneHash)->firstOrFail();
    expect($customer->is_best_customer)->toBeTrue();

    $returnedOrder = makeOrder($admin->business_id, [
        'customer_phone' => '0600000000',
        'customer_phone_hash' => $phoneHash,
        'confirmation_status' => OrderConfirmationStatus::SUBMITTED_TO_COURIER,
        'delivery_status' => OrderDeliveryStatus::RETURNED_IN_TRANSIT,
        'courier_tracking_number' => 'TRACK-1',
    ]);
    returnOrder($service, $returnedOrder, $admin);

    $customer->refresh();
    expect($customer->returned_orders_count)->toBe(1);
    expect($customer->is_best_customer)->toBeFalse();
});

test('the courier claiming a return in transit does not by itself affect best-customer status', function () {
    $admin = makeBusinessUser();
    $service = app(OrderService::class);
    $phoneHash = hash('sha256', '0600000000');

    foreach (range(1, 3) as $i) {
        $order = makeOrder($admin->business_id, [
            'customer_phone' => '0600000000',
            'customer_phone_hash' => $phoneHash,
            'confirmation_status' => OrderConfirmationStatus::NEW,
        ]);
        app('events')->dispatch(new OrderCreated($order));
        deliverOrder($service, $order, $admin);
    }

    $order = makeOrder($admin->business_id, [
        'customer_phone' => '0600000000',
        'customer_phone_hash' => $phoneHash,
        'confirmation_status' => OrderConfirmationStatus::SUBMITTED_TO_COURIER,
        'courier_tracking_number' => 'TRACK-1',
    ]);
    $service->updateDeliveryStatus($order, OrderDeliveryStatus::RETURNED_IN_TRANSIT, null, OrderReturnReason::CLIENT_REFUSED);

    $customer = Customer::where('business_id', $admin->business_id)->where('phone_hash', $phoneHash)->firstOrFail();
    expect($customer->returned_orders_count)->toBe(0);
    expect($customer->is_best_customer)->toBeTrue();
});

test('an order matching a blacklist entry flags the customer as blacklisted and clears best-customer status', function () {
    $admin = makeBusinessUser();
    $service = app(OrderService::class);
    $phoneHash = hash('sha256', '0600000000');

    foreach (range(1, 3) as $i) {
        $order = makeOrder($admin->business_id, [
            'customer_phone' => '0600000000',
            'customer_phone_hash' => $phoneHash,
            'confirmation_status' => OrderConfirmationStatus::NEW,
        ]);
        app('events')->dispatch(new OrderCreated($order));
        deliverOrder($service, $order, $admin);
    }

    $customer = Customer::where('business_id', $admin->business_id)->where('phone_hash', $phoneHash)->firstOrFail();
    expect($customer->is_best_customer)->toBeTrue();

    CustomerBlacklistEntry::create([
        'business_id' => $admin->business_id,
        'phone_hash' => $phoneHash,
        'phone_encrypted' => '0600000000',
    ]);

    $newOrder = makeOrder($admin->business_id, [
        'customer_phone' => '0600000000',
        'customer_phone_hash' => $phoneHash,
    ]);
    app('events')->dispatch(new OrderCreated($newOrder));

    $customer->refresh();
    expect($customer->is_blacklisted)->toBeTrue();
    expect($customer->is_best_customer)->toBeFalse();
    expect($newOrder->fresh()->is_blacklist_flagged)->toBeTrue();
});
