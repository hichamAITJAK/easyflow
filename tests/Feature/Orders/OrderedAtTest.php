<?php

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('creating a manual order sets ordered_at alongside created_at', function () {
    $admin = makeBusinessUser();

    $response = $this->actingAs($admin)->post(route('orders.store'), [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ]);

    $response->assertRedirect(route('orders.index'));

    $order = Order::where('business_id', $admin->business_id)->firstOrFail();

    expect($order->ordered_at)->not->toBeNull();
    expect($order->ordered_at->diffInSeconds($order->created_at))->toBeLessThan(2);
});

test('the orders index date_from/date_to filters operate on ordered_at, not created_at', function () {
    $admin = makeBusinessUser();

    $inRange = makeOrder($admin->business_id, [
        'reference' => 'in-range',
        'ordered_at' => '2024-06-15 12:00:00',
        'created_at' => '2024-01-01 00:00:00',
    ]);

    $outOfRange = makeOrder($admin->business_id, [
        'reference' => 'out-of-range',
        'ordered_at' => '2024-01-01 00:00:00',
        'created_at' => '2024-06-15 12:00:00',
    ]);

    $response = $this->actingAs($admin)->get(route('orders.index', [
        'date_from' => '2024-06-01',
        'date_to' => '2024-06-30',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('orders.data', 1)
        ->where('orders.data.0.id', $inRange->id)
    );

    expect(Order::find($outOfRange->id))->not->toBeNull();
});
