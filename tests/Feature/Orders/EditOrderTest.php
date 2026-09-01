<?php

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an order\'s customer details and items can be edited', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'total_amount' => 100,
    ]);
    OrderItem::create([
        'business_id' => $admin->business_id,
        'order_id' => $order->id,
        'product_name_snapshot' => 'Old Product',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    $response = $this->actingAs($admin)->patch(route('orders.update', $order), [
        'customer_name' => 'John Smith',
        'customer_phone' => '+212611111111',
        'customer_address' => '456 New St',
        'total_amount' => 250,
        'items' => [
            [
                'product_name' => 'New Product',
                'quantity' => 2,
                'unit_price' => 125,
            ],
        ],
    ]);

    $response->assertRedirect(route('orders.index'));

    $order->refresh();
    expect($order->customer_name)->toBe('John Smith');
    expect((float) $order->total_amount)->toBe(250.0);

    $items = $order->items;
    expect($items)->toHaveCount(1);
    expect($items->first()->product_name_snapshot)->toBe('New Product');
    expect($items->first()->quantity)->toBe(2);
});

test('editing an order replaces its line items rather than appending', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id);
    OrderItem::create([
        'business_id' => $admin->business_id,
        'order_id' => $order->id,
        'product_name_snapshot' => 'Item A',
        'quantity' => 1,
        'unit_price' => 50,
    ]);

    $this->actingAs($admin)->patch(route('orders.update', $order), [
        'customer_name' => $order->customer_name,
        'customer_phone' => $order->customer_phone,
        'customer_address' => $order->customer_address,
        'total_amount' => 50,
        'items' => [
            ['product_name' => 'Item B', 'quantity' => 1, 'unit_price' => 50],
        ],
    ]);

    expect($order->items()->count())->toBe(1);
    expect($order->items()->first()->product_name_snapshot)->toBe('Item B');
    expect(OrderItem::withTrashed()->where('order_id', $order->id)->count())->toBe(2);
});

test('an order cannot be edited once it has shipped', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['delivery_status' => 'awaiting_pickup']);

    $this->actingAs($admin)
        ->patch(route('orders.update', $order), [
            'customer_name' => 'John Smith',
            'customer_phone' => $order->customer_phone,
            'customer_address' => $order->customer_address,
            'total_amount' => 100,
        ])
        ->assertForbidden();

    expect($order->fresh()->customer_name)->not->toBe('John Smith');
});

test('an admin cannot edit an order belonging to another business', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();
    $order = makeOrder($otherAdmin->business_id);

    $this->actingAs($admin)
        ->patch(route('orders.update', $order), [
            'customer_name' => 'John Smith',
            'customer_phone' => $order->customer_phone,
            'customer_address' => $order->customer_address,
            'total_amount' => 100,
        ])
        ->assertNotFound();
});

test('an order note can be added, changed and cleared', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['total_amount' => 100]);

    $payload = [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
    ];

    $this->actingAs($admin)
        ->patch(route('orders.update', $order), $payload + ['notes' => 'Ring the bell twice'])
        ->assertRedirect();
    expect($order->refresh()->notes)->toBe('Ring the bell twice');

    $this->actingAs($admin)
        ->patch(route('orders.update', $order), $payload + ['notes' => 'Call after 6pm'])
        ->assertRedirect();
    expect($order->refresh()->notes)->toBe('Call after 6pm');

    // An emptied textarea clears the note rather than storing "".
    $this->actingAs($admin)
        ->patch(route('orders.update', $order), $payload + ['notes' => ''])
        ->assertRedirect();
    expect($order->refresh()->notes)->toBeNull();
});

test('a manually created order stores its note', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('orders.store'), [
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_address' => '123 Main St',
        'total_amount' => 100,
        'notes' => 'Confirm the colour before shipping',
    ])->assertRedirect();

    expect(Order::where('business_id', $admin->business_id)->firstOrFail()->notes)
        ->toBe('Confirm the colour before shipping');
});
