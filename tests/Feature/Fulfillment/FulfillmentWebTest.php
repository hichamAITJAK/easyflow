<?php

use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The browser-side fulfilment workspace.
 *
 * The scan rules themselves (which status permits which action, the undo
 * window, the audit reads) are covered once against the mobile endpoints
 * in tests/Feature/Api/FulfillmentTest.php — the web controller delegates
 * to that same controller, so re-testing them here would only assert that
 * PHP method calls work. What is genuinely different on the web side, and
 * so is what these tests cover, is session auth instead of Sanctum, the
 * role gate, and the post-login landing route.
 */
test('a fulfilment agent lands on the scan workspace with their counters', function () {
    $user = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);

    makeOrder($user->business_id, [
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
        'courier_tracking_number' => 'T1',
    ]);
    makeOrder($user->business_id, [
        'delivery_status' => OrderDeliveryStatus::RETURNED_IN_TRANSIT,
        'courier_tracking_number' => 'T2',
    ]);

    $this->actingAs($user)
        ->get(route('fulfillment.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('fulfillment/index')
            ->where('summary.ready_to_prepare', 1)
            ->where('summary.returns_pending', 1));
});

test('an admin may open the workspace to cover the warehouse', function () {
    $user = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($user)
        ->get(route('fulfillment.index'))
        ->assertOk();
});

test('a confirmation agent is refused the workspace', function () {
    $user = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    $this->actingAs($user)
        ->get(route('fulfillment.index'))
        ->assertForbidden();
});

test('a guest is sent to log in rather than shown the workspace', function () {
    $this->get(route('fulfillment.index'))->assertRedirect(route('login'));
});

test('scanning over the session-authenticated web route previews without mutating', function () {
    $user = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-WEB-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('fulfillment.scan'), ['qr_value' => 'TRACK-WEB-1']);

    $response->assertOk();
    $response->assertJson(['action' => 'ready_for_pickup']);
    expect($response->json('order.id'))->toBe($order->id);
    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::AWAITING_PICKUP);
});

test('confirming over the web route commits the transition', function () {
    $user = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-WEB-2',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $this->actingAs($user)
        ->postJson(route('fulfillment.confirm'), ['order_id' => $order->id])
        ->assertOk();

    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::READY_FOR_PICKUP);
});

test('a parcel belonging to another business is not found', function () {
    $user = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);
    $stranger = makeBusinessUser();

    makeOrder($stranger->business_id, [
        'courier_tracking_number' => 'TRACK-OTHER',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $this->actingAs($user)
        ->postJson(route('fulfillment.scan'), ['qr_value' => 'TRACK-OTHER'])
        ->assertStatus(422);
});

test('the activity log lists this agent scans for today', function () {
    $user = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-WEB-3',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $this->actingAs($user)->postJson(route('fulfillment.confirm'), ['order_id' => $order->id]);

    $response = $this->actingAs($user)->getJson(route('fulfillment.activity'));

    $response->assertOk();
    expect($response->json('events'))->toHaveCount(1);
    expect($response->json('events.0.tracking_number'))->toBe('TRACK-WEB-3');
    expect($response->json('events.0.can_undo'))->toBeTrue();
});

test('undoing a scan over the web route reverts the parcel', function () {
    $user = makeBusinessUser(['role' => UserRole::FULFILMENT_AGENT]);
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-WEB-4',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $this->actingAs($user)->postJson(route('fulfillment.confirm'), ['order_id' => $order->id]);

    $eventId = $this->actingAs($user)->getJson(route('fulfillment.activity'))->json('events.0.id');

    $this->actingAs($user)
        ->postJson(route('fulfillment.undo'), ['event_id' => $eventId])
        ->assertOk();

    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::AWAITING_PICKUP);
});
