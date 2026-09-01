<?php

use App\DTOs\Storeep\StoreepOrderDTO;

/**
 * An order as Storeep's GET /orders returns it, trimmed to the fields
 * these tests exercise.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storeepOrder(array $overrides = []): array
{
    return [
        'id' => 298437652918374563,
        'number' => 1042,
        'subtotal' => 59.98,
        'discount' => 5.00,
        'shipping' => 4.99,
        'vat' => null,
        'total' => 59.97,
        'currency' => 'USD',
        'market' => 'US',
        'status' => 'confirmed',
        'is_test' => false,
        'created_at' => '2025-02-10 08:15:00',
        'items' => [
            [
                'name' => 'Classic Cotton T-Shirt',
                'price' => 29.99,
                'quantity' => 2,
                'sku' => 'SHIRT-M-BLUE',
                'barcode' => '1234567890123',
                'variants' => [
                    ['type' => 'text', 'name' => 'Size', 'options' => [['name' => 'M']]],
                ],
            ],
        ],
        'addresses' => [
            [
                'type' => 'shipping',
                'fullname' => 'John Doe',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'address1' => '123 Main St',
                'address2' => 'Apt 4B',
                'apartment' => '4B',
                'floor' => '4',
                'neighborhood' => 'Downtown',
                'city' => 'Los Angeles',
                'state_or_region' => 'California',
                'postal_code' => '90001',
                'phone' => '+1234567890',
                'note' => 'Leave at door',
            ],
        ],
        ...$overrides,
    ];
}

test('the customer identity comes from the shipping address', function () {
    // Storeep has no customer object at all — the address is the only place
    // a name or phone appears.
    $dto = StoreepOrderDTO::fromArray(storeepOrder());

    expect($dto->customerName())->toBe('John Doe');
    expect($dto->shippingAddress->phone)->toBe('+1234567890');
});

test('the shipping address is picked out of the address list by type', function () {
    $dto = StoreepOrderDTO::fromArray(storeepOrder([
        'addresses' => [
            ['type' => 'billing', 'fullname' => 'Billing Person', 'city' => 'Rabat'],
            ['type' => 'shipping', 'fullname' => 'Shipping Person', 'city' => 'Casablanca'],
        ],
    ]));

    expect($dto->shippingAddress->city)->toBe('Casablanca');
});

test('an address list with no shipping entry falls back to the first', function () {
    $dto = StoreepOrderDTO::fromArray(storeepOrder([
        'addresses' => [['type' => 'billing', 'fullname' => 'Only Address', 'city' => 'Rabat']],
    ]));

    expect($dto->shippingAddress->city)->toBe('Rabat');
});

test('an order with no addresses has no shipping address or customer name', function () {
    $dto = StoreepOrderDTO::fromArray(storeepOrder(['addresses' => []]));

    expect($dto->shippingAddress)->toBeNull();
    expect($dto->customerName())->toBeNull();
});

test('apartment floor and neighborhood are folded into the second address line', function () {
    // OrderSyncService joins first_line + second_line into the single
    // address string an agent reads out on the phone, so routable detail
    // must land in one of those two.
    $address = StoreepOrderDTO::fromArray(storeepOrder())->toArray()['shipping_address'];

    expect($address['first_line'])->toBe('123 Main St');
    expect($address['second_line'])->toBe('Apt 4B, Apt 4B, Floor 4, Downtown');
});

test('the order market fills in the address country code', function () {
    // Storeep's address carries no country field; the order's market does.
    $address = StoreepOrderDTO::fromArray(storeepOrder())->toArray()['shipping_address'];

    expect($address['country_code'])->toBe('US');
});

test('line items carry no product or variant id, only a sku', function () {
    // Storeep's order items have no product/variant identifiers, so variant
    // matching downstream falls back to sku and product_id lands null.
    $item = StoreepOrderDTO::fromArray(storeepOrder())->toArray()['line_items'][0];

    expect($item['product_id'])->toBeNull();
    expect($item['variant_id'])->toBeNull();
    expect($item['sku'])->toBe('SHIRT-M-BLUE');
    expect($item['variant_title'])->toBe('M');
});

test('the merchant-facing order number is used as the reference', function () {
    expect(StoreepOrderDTO::fromArray(storeepOrder())->reference)->toBe('1042');
    expect(StoreepOrderDTO::fromArray(storeepOrder(['number' => null]))->reference)
        ->toBe('298437652918374563');
});

test('shipping is read as the delivery cost', function () {
    $data = StoreepOrderDTO::fromArray(storeepOrder())->toArray();

    expect($data['total'])->toBe(59.97);
    expect($data['delivery_cost'])->toBe(4.99);
});

test('an order list unwraps the data envelope', function () {
    $orders = StoreepOrderDTO::fromList(['data' => [storeepOrder(), storeepOrder(['id' => 2])]]);

    expect($orders)->toHaveCount(2);
    expect($orders[1]->id)->toBe('2');
});

test('toArray emits the keys OrderSyncService reads', function () {
    $data = StoreepOrderDTO::fromArray(storeepOrder())->toArray();

    expect($data['id'])->toBe('298437652918374563');
    expect($data['customer_name'])->toBe('John Doe');
    expect($data['customer_phone'])->toBe('+1234567890');
    expect($data['created_at'])->toBe('2025-02-10 08:15:00');
    expect($data['line_items'][0]['quantity'])->toBe(2);
    expect($data['line_items'][0]['price'])->toBe(29.99);
});
