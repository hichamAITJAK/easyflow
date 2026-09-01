<?php

use App\DTOs\Storeep\StoreepProductDTO;

/**
 * A product as Storeep's GET /products returns it, trimmed to the fields
 * these tests exercise.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storeepProduct(array $overrides = []): array
{
    return [
        'id' => 298437652918374561,
        'name' => 'Classic Cotton T-Shirt',
        'media' => [
            ['type' => 'image', 'url' => 'https://cdn.storeep.com/shirt.jpg'],
        ],
        'variants' => [
            ['type' => 'text', 'name' => 'Size', 'options' => [['name' => 'S'], ['name' => 'M']]],
        ],
        'is_published' => true,
        'options' => [
            [
                'id' => 298437652918374562,
                'sku' => 'SHIRT-S-RED',
                'barcode' => '1234567890123',
                'weight' => 0.25,
                'markets' => [
                    ['market' => 'US', 'currency' => 'USD', 'price' => 29.99, 'discounted_price' => 24.99],
                    ['market' => 'EU', 'currency' => 'EUR', 'price' => 27.99, 'discounted_price' => null],
                ],
            ],
        ],
        ...$overrides,
    ];
}

test('storeep options become our variants, not its variants array', function () {
    // Storeep inverts the usual naming: its `options[]` are the purchasable
    // rows and its `variants[]` are the option axes.
    $dto = StoreepProductDTO::fromArray(storeepProduct());

    expect($dto->variants)->toHaveCount(1);
    expect($dto->variants[0]->sku)->toBe('SHIRT-S-RED');
    expect($dto->optionAxes)->toBe([
        ['name' => 'Size', 'type' => 'text', 'values' => ['S', 'M']],
    ]);
});

test('is_published true maps to active and false maps to inactive', function () {
    expect(StoreepProductDTO::fromArray(storeepProduct(['is_published' => true]))->status)->toBe('active');
    expect(StoreepProductDTO::fromArray(storeepProduct(['is_published' => false]))->status)->toBe('inactive');
});

test('the preferred market decides which pricing row is used', function () {
    $usd = StoreepProductDTO::fromArray(storeepProduct(), 'US');
    $eur = StoreepProductDTO::fromArray(storeepProduct(), 'EU');

    // US has a discount, so the discounted price is what the customer pays.
    expect($usd->variants[0]->price)->toBe(24.99);
    expect($usd->variants[0]->currency)->toBe('USD');

    // EU has no discount, so the list price stands.
    expect($eur->variants[0]->price)->toBe(27.99);
    expect($eur->variants[0]->currency)->toBe('EUR');
});

test('an unknown or missing market falls back to the first pricing row', function () {
    expect(StoreepProductDTO::fromArray(storeepProduct(), 'JP')->variants[0]->currency)->toBe('USD');
    expect(StoreepProductDTO::fromArray(storeepProduct())->variants[0]->currency)->toBe('USD');
});

test('a video-only media list falls back to the video thumbnail', function () {
    $dto = StoreepProductDTO::fromArray(storeepProduct([
        'media' => [
            ['type' => 'video', 'id' => 'dQw4w9WgXcQ', 'thumbnail' => 'https://img.youtube.com/vi/x/hq.jpg'],
        ],
    ]));

    expect($dto->imageUrl)->toBe('https://img.youtube.com/vi/x/hq.jpg');
});

test('an image is preferred over an earlier video entry', function () {
    $dto = StoreepProductDTO::fromArray(storeepProduct([
        'media' => [
            ['type' => 'video', 'id' => 'x', 'thumbnail' => 'https://img.youtube.com/vi/x/hq.jpg'],
            ['type' => 'image', 'url' => 'https://cdn.storeep.com/real.jpg'],
        ],
    ]));

    expect($dto->imageUrl)->toBe('https://cdn.storeep.com/real.jpg');
});

test('stock is unknown rather than zero, since storeep reports no inventory', function () {
    // 0 would read downstream as "out of stock" — a different claim than
    // "this platform doesn't tell us".
    $dto = StoreepProductDTO::fromArray(storeepProduct());

    expect($dto->variants[0]->inventoryQuantity)->toBeNull();
    expect($dto->variants[0]->available)->toBeTrue();
});

test('a product list unwraps the data envelope', function () {
    $products = StoreepProductDTO::fromList(['data' => [storeepProduct(), storeepProduct(['id' => 2])]]);

    expect($products)->toHaveCount(2);
    expect($products[1]->id)->toBe('2');
});

test('toArray emits the keys ProductSyncService reads', function () {
    $data = StoreepProductDTO::fromArray(storeepProduct(), 'US')->toArray();

    expect($data['id'])->toBe('298437652918374561');
    expect($data['title'])->toBe('Classic Cotton T-Shirt');
    expect($data['status'])->toBe('active');
    expect($data['image_url'])->toBe('https://cdn.storeep.com/shirt.jpg');
    expect($data['variants'][0])->toMatchArray([
        'id' => '298437652918374562',
        'sku' => 'SHIRT-S-RED',
        'price' => 24.99,
        'inventory_quantity' => null,
        'available' => true,
    ]);
});
