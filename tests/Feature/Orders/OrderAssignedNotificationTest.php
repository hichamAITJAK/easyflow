<?php

use App\Enums\OrderConfirmationStatus;
use App\Models\DeviceToken;
use App\Notifications\OrderAssignedNotification;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('assigning an order to an agent sends them a push notification', function () {
    Notification::fake();

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);

    app(OrderService::class)->assign($order, $agent->id, $admin);

    Notification::assertSentTo(
        $agent,
        OrderAssignedNotification::class,
        fn ($notification) => $notification->toExpoPush($agent)['data']['order_id'] === $order->id,
    );
});

test('unassigning an order sends no notification', function () {
    Notification::fake();

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => OrderConfirmationStatus::ASSIGNED,
        'assigned_agent_id' => $agent->id,
    ]);

    app(OrderService::class)->assign($order, null, $admin);

    Notification::assertNotSentTo($agent, OrderAssignedNotification::class);
});

test('the notification fans out to every device token the agent has registered', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'ok']], 200)]);

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);
    DeviceToken::create(['user_id' => $agent->id, 'token' => 'ExponentPushToken[phone]']);
    DeviceToken::create(['user_id' => $agent->id, 'token' => 'ExponentPushToken[tablet]']);

    $order = makeOrder($admin->business_id, [
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);

    app(OrderService::class)->assign($order, $agent->id, $admin);

    // One request carrying both devices, not one request per device —
    // Expo batches up to 100 messages per call. What matters is that
    // neither device was dropped.
    Http::assertSentCount(1);

    Http::assertSent(function ($request) {
        $tokens = array_column($request->data(), 'to');

        return in_array('ExponentPushToken[phone]', $tokens, true)
            && in_array('ExponentPushToken[tablet]', $tokens, true);
    });
});

test('an agent with no registered device tokens triggers no HTTP calls', function () {
    Http::fake();

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);

    app(OrderService::class)->assign($order, $agent->id, $admin);

    Http::assertNothingSent();
});

test('a failed Expo push send does not throw and does not block the assignment', function () {
    Http::fake(['exp.host/*' => Http::response('Service unavailable', 500)]);

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);
    DeviceToken::create(['user_id' => $agent->id, 'token' => 'ExponentPushToken[phone]']);

    $order = makeOrder($admin->business_id, [
        'confirmation_status' => OrderConfirmationStatus::NEW,
    ]);

    $updated = app(OrderService::class)->assign($order, $agent->id, $admin);

    expect($updated->assigned_agent_id)->toBe($agent->id);
});
