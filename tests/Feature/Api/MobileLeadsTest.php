<?php

use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function actingAsConfirmationAgent(): array
{
    $user = makeBusinessUser(['role' => 'confirmation_agent']);
    $token = $user->createToken('device')->plainTextToken;

    return [$user, $token];
}

test('the leads index only returns orders assigned to the authenticated agent', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherAgent = makeBusinessUser(['role' => 'confirmation_agent', 'business_id' => $user->business_id]);

    $own = makeOrder($user->business_id, ['assigned_agent_id' => $user->id]);
    makeOrder($user->business_id, ['assigned_agent_id' => $otherAgent->id]);
    makeOrder($user->business_id, ['assigned_agent_id' => null]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.index'));

    $response->assertOk();
    $ids = collect($response->json('leads'))->pluck('id');

    expect($ids)->toHaveCount(1);
    expect($ids)->toContain($own->id);
});

test('the leads index only returns orders for the authenticated user\'s business', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherUser = makeBusinessUser(['role' => 'confirmation_agent']);

    makeOrder($otherUser->business_id, ['assigned_agent_id' => $otherUser->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.index'));

    $response->assertOk();
    expect($response->json('leads'))->toBeEmpty();
});

test('the leads index includes the client name and phone, unlike the model default', function () {
    [$user, $token] = actingAsConfirmationAgent();
    makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'customer_name' => 'Nadia Amrani',
        'customer_phone' => '+212611223344',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.index'));

    $response->assertOk();
    expect($response->json('leads.0.customer_name'))->toBe('Nadia Amrani');
    expect($response->json('leads.0.customer_phone'))->toBe('+212611223344');
});

test('a product line item includes its resolved thumbnail, preferring the variant image over the product thumbnail', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $user->id]);

    $product = Product::create([
        'business_id' => $user->business_id,
        'name' => 'Wireless Earbuds',
        'price' => 249,
        'thumbnail' => 'product-images/earbuds.jpg',
    ]);
    $variant = ProductVariant::create([
        'business_id' => $user->business_id,
        'product_id' => $product->id,
        'image' => 'variant-images/earbuds-black.jpg',
    ]);
    OrderItem::create([
        'business_id' => $user->business_id,
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => 'Wireless Earbuds — Black',
        'quantity' => 1,
        'unit_price' => 249,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.show', $order));

    $response->assertOk();
    expect($response->json('lead.products.0.thumbnail'))->toBe('/storage/variant-images/earbuds-black.jpg');
});

test('a product line item falls back to the product thumbnail when its variant has no image', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $user->id]);

    $product = Product::create([
        'business_id' => $user->business_id,
        'name' => 'Wireless Earbuds',
        'price' => 249,
        'thumbnail' => 'product-images/earbuds.jpg',
    ]);
    OrderItem::create([
        'business_id' => $user->business_id,
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name_snapshot' => 'Wireless Earbuds',
        'quantity' => 1,
        'unit_price' => 249,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.show', $order));

    $response->assertOk();
    expect($response->json('lead.products.0.thumbnail'))->toBe('/storage/product-images/earbuds.jpg');
});

test('a line item with no linked product or variant has a null thumbnail', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $user->id]);

    OrderItem::create([
        'business_id' => $user->business_id,
        'order_id' => $order->id,
        'product_name_snapshot' => 'Manually entered item',
        'quantity' => 1,
        'unit_price' => 50,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.show', $order));

    $response->assertOk();
    expect($response->json('lead.products.0.thumbnail'))->toBeNull();
});

test('the leads index filter=new returns new and assigned statuses only', function () {
    [$user, $token] = actingAsConfirmationAgent();
    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::ASSIGNED]);
    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::CONFIRMED]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.index', ['filter' => 'new']));

    $response->assertOk();
    expect($response->json('leads'))->toHaveCount(1);
    expect($response->json('leads.0.confirmation_status'))->toBe('assigned');
});

test('the leads index filter=follow_up groups every post-contact-attempt status', function () {
    [$user, $token] = actingAsConfirmationAgent();
    foreach ([
        OrderConfirmationStatus::CONFIRMED_FOLLOWUP,
        OrderConfirmationStatus::CALLBACK,
        OrderConfirmationStatus::VOICEMAIL,
        OrderConfirmationStatus::NO_ANSWER,
        OrderConfirmationStatus::BUSY,
        OrderConfirmationStatus::WHATSAPP_SENT,
    ] as $status) {
        makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => $status]);
    }
    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::CONFIRMED]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.index', ['filter' => 'follow_up']));

    $response->assertOk();
    expect($response->json('leads'))->toHaveCount(6);
});

test('the leads counts endpoint tallies every bucket, scoped to the agent', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherAgent = makeBusinessUser(['role' => 'confirmation_agent', 'business_id' => $user->business_id]);

    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::ASSIGNED]);
    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::CALLBACK]);
    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::CONFIRMED]);
    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::SUBMITTED_TO_COURIER]);
    makeOrder($user->business_id, ['assigned_agent_id' => $otherAgent->id, 'confirmation_status' => OrderConfirmationStatus::ASSIGNED]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.counts'));

    $response->assertOk();
    $response->assertJson([
        'all' => 4,
        'new' => 1,
        'follow_up' => 1,
        'confirmed' => 1,
        'shipped' => 1,
    ]);
});

test('an agent cannot view a lead assigned to a different agent', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherAgent = makeBusinessUser(['role' => 'confirmation_agent', 'business_id' => $user->business_id]);
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $otherAgent->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.show', $order));

    $response->assertForbidden();
});

test('an agent can update a lead\'s status to confirmed', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'confirmation_status' => OrderConfirmationStatus::ASSIGNED,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.leads.update-status', $order), [
            'confirmation_status' => OrderConfirmationStatus::CONFIRMED->value,
        ]);

    $response->assertOk();
    $response->assertJson(['lead' => ['confirmation_status' => 'confirmed']]);
    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::CONFIRMED);
});

test('cancelling a lead without a reason code is rejected', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $user->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.leads.update-status', $order), [
            'confirmation_status' => OrderConfirmationStatus::CANCELLED->value,
        ]);

    $response->assertUnprocessable();
});

test('cancelling a lead with a reason code succeeds', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $user->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.leads.update-status', $order), [
            'confirmation_status' => OrderConfirmationStatus::CANCELLED->value,
            'cancellation_reason_code' => OrderCancelReason::CLIENT_UNREACHABLE->value,
        ]);

    $response->assertOk();
    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmationStatus::CANCELLED);
});

test('an agent cannot update a lead assigned to a different agent', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherAgent = makeBusinessUser(['role' => 'confirmation_agent', 'business_id' => $user->business_id]);
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $otherAgent->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.leads.update-status', $order), [
            'confirmation_status' => OrderConfirmationStatus::CONFIRMED->value,
        ]);

    $response->assertForbidden();
});

test('an agent can update their own lead\'s customer details and note', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $user->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.leads.update', $order), [
            'customer_name' => 'Karim Alaoui',
            'customer_phone' => '0612345678',
            'customer_address' => '12 Rue Hassan II',
            'customer_city' => 'Casablanca',
            'total_amount' => 340.0,
            'notes' => 'Deliver after 6pm.',
        ]);

    $response->assertOk();
    expect($response->json('lead.customer_name'))->toBe('Karim Alaoui');
    expect($response->json('lead.customer_city'))->toBe('Casablanca');
    expect($response->json('lead.notes'))->toBe('Deliver after 6pm.');

    $order->refresh();
    expect($order->customer_address)->toBe('12 Rue Hassan II');
    expect((float) $order->total_amount)->toBe(340.0);
});

test('updating a lead replaces its line items', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $user->id]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.leads.update', $order), [
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_address' => $order->customer_address ?? 'Address',
            'total_amount' => 200.0,
            'items' => [
                ['product_name' => 'Blue shirt', 'quantity' => 2, 'unit_price' => 75.0],
                ['product_name' => 'Belt', 'quantity' => 1, 'unit_price' => 50.0],
            ],
        ])
        ->assertOk();

    $items = $order->refresh()->items;
    expect($items)->toHaveCount(2);
    expect($items->pluck('product_name_snapshot')->all())->toBe(['Blue shirt', 'Belt']);
    expect($items->firstWhere('product_name_snapshot', 'Blue shirt')->quantity)->toBe(2);
});

test('an agent cannot edit a lead assigned to a different agent', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherAgent = makeBusinessUser(['business_id' => $user->business_id, 'role' => 'confirmation_agent']);
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $otherAgent->id, 'customer_name' => 'Original']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.leads.update', $order), [
            'customer_name' => 'Changed',
            'customer_phone' => '0600000000',
            'customer_address' => 'Somewhere',
            'total_amount' => 10.0,
        ])
        ->assertForbidden();

    expect($order->fresh()->customer_name)->toBe('Original');
});

test('a shipped lead can no longer be edited', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, [
        'assigned_agent_id' => $user->id,
        'customer_name' => 'Original',
        'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.leads.update', $order), [
            'customer_name' => 'Changed',
            'customer_phone' => '0600000000',
            'customer_address' => 'Somewhere',
            'total_amount' => 10.0,
        ])
        ->assertForbidden();

    expect($order->fresh()->customer_name)->toBe('Original');
});

test('an empty note clears the column rather than storing an empty string', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'notes' => 'Previous note.']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.leads.update', $order), [
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_address' => $order->customer_address ?? 'Address',
            'total_amount' => 10.0,
            'notes' => '',
        ])
        ->assertOk();

    expect($order->fresh()->notes)->toBeNull();
});

test('the products endpoint lists the business\'s active products', function () {
    [$user, $token] = actingAsConfirmationAgent();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.leads.products'));

    $response->assertOk();
    expect($response->json('products'))->toBeArray();
});
