<?php

use App\Enums\OrderDeliveryStatus;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A parcel awaiting pickup holding 2 × a black variant (stock 10) and
 * 1 × a product without variants (stock 5).
 *
 * @return array{0: User, 1: string, 2: ProductVariant, 3: Product, 4: int}
 */
function parcelWithStock(): array
{
    $user = makeBusinessUser(['role' => 'fulfilment_agent']);
    $token = $user->createToken('device')->plainTextToken;
    $businessId = $user->business_id;

    $serum = Product::factory()->create(['business_id' => $businessId, 'name' => 'Serum']);
    $color = ProductOption::factory()->create(['business_id' => $businessId, 'product_id' => $serum->id, 'name' => 'Color']);
    $black = ProductOptionValue::factory()->create(['business_id' => $businessId, 'product_option_id' => $color->id, 'value' => 'Black']);
    $variant = ProductVariant::factory()->create(['business_id' => $businessId, 'product_id' => $serum->id, 'inventory_quantity' => 10]);
    $variant->optionValues()->attach($black->id);

    $pillow = Product::factory()->create(['business_id' => $businessId, 'name' => 'Pillow', 'inventory_quantity' => 5]);

    $order = makeOrder($businessId, ['courier_tracking_number' => 'TRACK-S', 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP]);
    OrderItem::factory()->create(['business_id' => $businessId, 'order_id' => $order->id, 'product_id' => $serum->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
    OrderItem::factory()->create(['business_id' => $businessId, 'order_id' => $order->id, 'product_id' => $pillow->id, 'quantity' => 1]);

    return [$user, $token, $variant, $pillow, $order->id];
}

test('scanning a parcel out takes its items out of stock and says what changed', function () {
    [, $token, $variant, $pillow, $orderId] = parcelWithStock();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.confirm'), ['order_id' => $orderId])
        ->assertOk()
        ->assertJsonPath('stock_changes.0', ['product' => 'Serum', 'variant' => 'Black', 'sku' => $variant->sku, 'ordered' => 2, 'change' => -2, 'before' => 10, 'after' => 8])
        ->assertJsonPath('stock_changes.1.product', 'Pillow')
        ->assertJsonPath('stock_changes.1.change', -1)
        ->assertJsonPath('stock_changes.1.after', 4);

    expect($variant->fresh()->inventory_quantity)->toBe(8)
        ->and($pillow->fresh()->inventory_quantity)->toBe(4)
        ->and(StockMovement::withoutGlobalScopes()->where('type', 'sale')->sum('quantity_change'))->toBe(-3);
});

test('undoing the scan puts the stock back, and a second scan takes it again only once', function () {
    [$user, $token, $variant, $pillow, $orderId] = parcelWithStock();
    $api = fn (string $route, array $body) => $this->withHeader('Authorization', "Bearer {$token}")->postJson(route($route), $body);

    $api('api.mobile.fulfillment.confirm', ['order_id' => $orderId])->assertOk();
    $eventId = OrderStatusEvent::where('order_id', $orderId)->where('to_status', 'ready_for_pickup')->value('id');

    $api('api.mobile.fulfillment.undo', ['event_id' => $eventId])
        ->assertOk()
        ->assertJsonPath('stock_changes.0.change', 2)
        ->assertJsonPath('stock_changes.0.after', 10);

    expect($variant->fresh()->inventory_quantity)->toBe(10)
        ->and($pillow->fresh()->inventory_quantity)->toBe(5);

    $api('api.mobile.fulfillment.confirm', ['order_id' => $orderId])->assertOk();

    expect($variant->fresh()->inventory_quantity)->toBe(8)
        ->and($pillow->fresh()->inventory_quantity)->toBe(4);
});

test('stock never goes below zero and untracked stock is left alone', function () {
    $user = makeBusinessUser(['role' => 'fulfilment_agent']);
    $token = $user->createToken('device')->plainTextToken;

    $low = Product::factory()->create(['business_id' => $user->business_id, 'inventory_quantity' => 1]);
    $untracked = Product::factory()->create(['business_id' => $user->business_id, 'inventory_quantity' => null]);
    $order = makeOrder($user->business_id, ['courier_tracking_number' => 'TRACK-L', 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP]);
    OrderItem::factory()->create(['business_id' => $user->business_id, 'order_id' => $order->id, 'product_id' => $low->id, 'quantity' => 3]);
    OrderItem::factory()->create(['business_id' => $user->business_id, 'order_id' => $order->id, 'product_id' => $untracked->id, 'quantity' => 1]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.mobile.fulfillment.confirm'), ['order_id' => $order->id])
        ->assertOk()
        ->assertJsonCount(1, 'stock_changes')
        ->assertJsonPath('stock_changes.0.change', -1)
        ->assertJsonPath('stock_changes.0.after', 0);

    expect($low->fresh()->inventory_quantity)->toBe(0)
        ->and($untracked->fresh()->inventory_quantity)->toBeNull();
});
