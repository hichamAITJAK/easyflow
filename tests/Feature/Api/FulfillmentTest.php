<?php

use App\Enums\OrderDeliveryStatus;
use App\Events\Order\ParcelReadyForPickup;
use App\Events\Order\ParcelReturnReceived;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function actingAsFulfilmentAgent(): array
{
    $user = makeBusinessUser(['role' => 'fulfilment_agent']);
    $token = $user->createToken('device')->plainTextToken;

    return [$user, $token];
}

/**
 * Scan-then-confirm in one helper: the API deliberately splits preview
 * from commit, but most tests only care about the end state.
 */
function scanAndConfirm(object $test, string $token, string $qrValue): TestResponse
{
    $orderId = $test->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => $qrValue])
        ->json('order.id');

    return $test->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.confirm'), ['order_id' => $orderId]);
}

test('the summary counts orders awaiting pickup and returns pending receipt, scoped to the business', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $otherUser = makeBusinessUser();

    makeOrder($user->business_id, ['delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP, 'courier_tracking_number' => 'T1']);
    makeOrder($user->business_id, ['delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP, 'courier_tracking_number' => 'T2']);
    makeOrder($user->business_id, ['delivery_status' => OrderDeliveryStatus::RETURNED_IN_TRANSIT, 'courier_tracking_number' => 'T3']);
    makeOrder($user->business_id, ['delivery_status' => OrderDeliveryStatus::DELIVERED, 'courier_tracking_number' => 'T4']);
    makeOrder($otherUser->business_id, ['delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP, 'courier_tracking_number' => 'T5']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.fulfillment.summary'));

    $response->assertOk();
    $response->assertJson(['ready_to_prepare' => 2, 'returns_pending' => 1]);
});

test('scanning a parcel awaiting pickup previews the ready_for_pickup action without mutating anything', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'TRACK-1']);

    $response->assertOk();
    $response->assertJson(['action' => 'ready_for_pickup']);
    expect($response->json('order.id'))->toBe($order->id);
    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::AWAITING_PICKUP);
});

test('scanning a parcel returned in transit previews the return_received action', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::RETURNED_IN_TRANSIT,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'TRACK-1']);

    $response->assertOk();
    $response->assertJson(['action' => 'return_received']);
});

test('every order previews on scan — a non-actionable status returns the card with a null action, not an error', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::DELIVERED,
        'customer_name' => 'Nadia Amrani',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'TRACK-1']);

    $response->assertOk();
    $response->assertJson(['action' => null]);
    expect($response->json('order.customer_name'))->toBe('Nadia Amrani');
    expect($response->json('order.delivery_status'))->toBe('delivered');
});

test('a parcel already ready_for_pickup previews with a null action — the scan is not silently re-applied', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::READY_FOR_PICKUP,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'TRACK-1']);

    $response->assertOk();
    $response->assertJson(['action' => null]);
});

test('the scan preview carries the parcel handling flags the removed queue screen used to supply', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $store = makeStore($user->business_id);
    makeOrder($user->business_id, [
        'store_id' => $store->id,
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
        'parcel_fragile' => true,
        'parcel_open' => true,
        'parcel_note' => 'Handle with care',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'TRACK-1']);

    $response->assertOk();
    expect($response->json('order.store'))->toBe($store->name);
    expect($response->json('order.parcel_fragile'))->toBeTrue();
    expect($response->json('order.parcel_open'))->toBeTrue();
    expect($response->json('order.parcel_note'))->toBe('Handle with care');
});

test('the scan preview lists the order items the agent has to pick', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    OrderItem::create([
        'business_id' => $user->business_id,
        'order_id' => $order->id,
        'product_name_snapshot' => 'Argan Oil 100ml',
        'sku_snapshot' => 'ARG-100',
        'quantity' => 2,
        'unit_price' => 149.00,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'TRACK-1']);

    $response->assertOk();
    expect($response->json('order.items'))->toHaveCount(1);
    expect($response->json('order.items.0.name'))->toBe('Argan Oil 100ml');
    expect($response->json('order.items.0.sku'))->toBe('ARG-100');
    expect($response->json('order.items.0.quantity'))->toBe(2);
});

test('an order with no line items previews with an empty items array, not a missing key', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'TRACK-1']);

    $response->assertOk();
    expect($response->json('order.items'))->toBe([]);
});

test('the confirm response carries the order items too, not just the scan preview', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    OrderItem::create([
        'business_id' => $user->business_id,
        'order_id' => $order->id,
        'product_name_snapshot' => 'Argan Oil 100ml',
        'sku_snapshot' => 'ARG-100',
        'quantity' => 2,
        'unit_price' => 149.00,
    ]);

    $response = scanAndConfirm($this, $token, 'TRACK-1');

    $response->assertOk();
    expect($response->json('order.items.0.name'))->toBe('Argan Oil 100ml');
});

test('a scanned QR carrying a tracking URL resolves via its last path segment', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'DH123456',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'https://app.sendit.ma/parcel/DH123456']);

    $response->assertOk();
    expect($response->json('order.id'))->toBe($order->id);
    $response->assertJson(['action' => 'ready_for_pickup']);
});

test('a scanned QR carrying the tracking number in a query parameter resolves to the order', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'OZ-99887',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), [
            'qr_value' => 'https://client.ozoneexpress.ma/pdf-delivery-note?dn-ref=OZ-99887',
        ]);

    $response->assertOk();
    expect($response->json('order.id'))->toBe($order->id);
});

test('a scanned value with surrounding whitespace still resolves', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => "  TRACK-1\n"]);

    $response->assertOk();
    expect($response->json('order.id'))->toBe($order->id);
});

test('scanning an unknown code returns a validation error on qr_value', function () {
    [, $token] = actingAsFulfilmentAgent();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'DOES-NOT-EXIST']);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('qr_value');
});

test('scanning a parcel belonging to another business is rejected as unknown', function () {
    [, $token] = actingAsFulfilmentAgent();
    $otherUser = makeBusinessUser();
    makeOrder($otherUser->business_id, [
        'courier_tracking_number' => 'TRACK-OTHER',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'TRACK-OTHER']);

    $response->assertUnprocessable();
});

test('confirming a parcel awaiting pickup sets ready_for_pickup without the client choosing an action', function () {
    Event::fake([ParcelReadyForPickup::class]);
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    $order->refresh();
    expect($order->delivery_status)->toBe(OrderDeliveryStatus::READY_FOR_PICKUP);
    expect($order->ready_for_pickup_at)->not->toBeNull();
    Event::assertDispatched(ParcelReadyForPickup::class, fn ($event) => $event->order->is($order));
});

test('confirming a parcel returned in transit sets return_received', function () {
    Event::fake([ParcelReturnReceived::class]);
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::RETURNED_IN_TRANSIT,
    ]);

    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    $order->refresh();
    expect($order->delivery_status)->toBe(OrderDeliveryStatus::RETURN_RECEIVED);
    expect($order->return_received_at)->not->toBeNull();
    Event::assertDispatched(ParcelReturnReceived::class, fn ($event) => $event->order->is($order));
});

test('confirming a parcel whose current status is not actionable is rejected and nothing changes', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::DELIVERED,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.confirm'), ['order_id' => $order->id]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('order_id');
    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::DELIVERED);
});

test('confirming a parcel whose status changed between preview and commit is rejected', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $orderId = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.scan'), ['qr_value' => 'TRACK-1'])
        ->json('order.id');

    $order->update(['delivery_status' => OrderDeliveryStatus::IN_TRANSIT]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.confirm'), ['order_id' => $orderId]);

    $response->assertUnprocessable();
});

test('confirming an order belonging to another business is not found', function () {
    [, $token] = actingAsFulfilmentAgent();
    $otherUser = makeBusinessUser();
    $order = makeOrder($otherUser->business_id, [
        'courier_tracking_number' => 'TRACK-OTHER',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.confirm'), ['order_id' => $order->id]);

    $response->assertNotFound();
    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::AWAITING_PICKUP);
});

test('the activity log lists only today\'s scans by the authenticated agent, newest first', function () {
    [$user, $token] = actingAsFulfilmentAgent();

    makeOrder($user->business_id, ['courier_tracking_number' => 'A', 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP]);
    makeOrder($user->business_id, ['courier_tracking_number' => 'B', 'delivery_status' => OrderDeliveryStatus::RETURNED_IN_TRANSIT]);

    scanAndConfirm($this, $token, 'A')->assertOk();
    scanAndConfirm($this, $token, 'B')->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.fulfillment.activity'));

    $response->assertOk();
    $events = $response->json('events');

    expect($events)->toHaveCount(2);
    expect($events[0]['tracking_number'])->toBe('B');
    expect($events[0]['to_status'])->toBe('return_received');
    expect($events[0]['can_undo'])->toBeTrue();
    expect($events[1]['tracking_number'])->toBe('A');
});

test('an undone scan disappears from the activity log', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    $eventId = OrderStatusEvent::where('order_id', $order->id)->latest('id')->first()->id;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.undo'), ['event_id' => $eventId])
        ->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.fulfillment.activity'));

    $response->assertOk();
    expect($response->json('events'))->toBeEmpty();
});

test('undoing one scan leaves the agent\'s other scans listed', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $undone = makeOrder($user->business_id, [
        'courier_tracking_number' => 'UNDONE',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);
    makeOrder($user->business_id, [
        'courier_tracking_number' => 'KEPT',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    scanAndConfirm($this, $token, 'UNDONE')->assertOk();
    scanAndConfirm($this, $token, 'KEPT')->assertOk();

    $eventId = OrderStatusEvent::where('order_id', $undone->id)->latest('id')->first()->id;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.undo'), ['event_id' => $eventId])
        ->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.fulfillment.activity'));

    $response->assertOk();
    $events = $response->json('events');

    expect($events)->toHaveCount(1);
    expect($events[0]['tracking_number'])->toBe('KEPT');
});

test('re-scanning a parcel after undoing it lists the new scan, not the undone one', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    $eventId = OrderStatusEvent::where('order_id', $order->id)->latest('id')->first()->id;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.undo'), ['event_id' => $eventId])
        ->assertOk();

    // Scanned again — a genuine action the agent took after the undo, so
    // it belongs in the log even though an earlier scan of the same parcel
    // was reversed.
    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.fulfillment.activity'));

    $response->assertOk();
    $events = $response->json('events');

    expect($events)->toHaveCount(1);
    expect($events[0]['tracking_number'])->toBe('TRACK-1');
    expect($events[0]['to_status'])->toBe('ready_for_pickup');
});

test('an unrelated later status change does not hide a real scan from the activity log', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    // The courier later reports the parcel as returning. That's a forward
    // transition, not a reversal of the agent's scan — the scan stays.
    OrderStatusEvent::create([
        'business_id' => $user->business_id,
        'order_id' => $order->id,
        'from_status' => OrderDeliveryStatus::IN_TRANSIT->value,
        'to_status' => OrderDeliveryStatus::RETURNED_IN_TRANSIT->value,
        'changed_by_user_id' => null,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.fulfillment.activity'));

    $response->assertOk();
    expect($response->json('events'))->toHaveCount(1);
});

test('undoing a recent scan reverts the delivery status through the normal transition path', function () {
    Event::fake([ParcelReadyForPickup::class]);
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    $eventId = OrderStatusEvent::where('order_id', $order->id)->latest('id')->first()->id;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.undo'), ['event_id' => $eventId]);

    $response->assertOk();
    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::AWAITING_PICKUP);
});

test('undoing a scan outside the time window is rejected', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    $event = OrderStatusEvent::where('order_id', $order->id)->latest('id')->first();
    $event->created_at = now()->subMinutes(10);
    $event->save();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.undo'), ['event_id' => $event->id]);

    $response->assertUnprocessable();
    expect($order->fresh()->delivery_status)->toBe(OrderDeliveryStatus::READY_FOR_PICKUP);
});

test('undoing a scan whose order has since moved to a different status is rejected', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    $order->update(['delivery_status' => OrderDeliveryStatus::IN_TRANSIT]);
    $event = OrderStatusEvent::where('order_id', $order->id)->latest('id')->first();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.undo'), ['event_id' => $event->id]);

    $response->assertUnprocessable();
});

test('undoing another agent\'s scan is rejected, even within the same business', function () {
    [$user, $token] = actingAsFulfilmentAgent();
    $otherAgent = makeBusinessUser(['business_id' => $user->business_id, 'role' => 'fulfilment_agent']);
    $otherToken = $otherAgent->createToken('device')->plainTextToken;
    $order = makeOrder($user->business_id, [
        'courier_tracking_number' => 'TRACK-1',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    scanAndConfirm($this, $token, 'TRACK-1')->assertOk();

    $event = OrderStatusEvent::where('order_id', $order->id)->latest('id')->first();

    // Laravel's auth guard resolution is memoized per test method; switching
    // which Sanctum token authenticates a request within the same test
    // requires clearing it first, or the previous request's user sticks.
    $this->app['auth']->forgetGuards();

    $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
        ->postJson(route('api.mobile.fulfillment.undo'), ['event_id' => $event->id]);

    $response->assertUnprocessable();
});
