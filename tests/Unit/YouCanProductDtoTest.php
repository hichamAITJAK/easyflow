<?php

use App\DTOs\YouCan\YouCanProductDTO;
use App\DTOs\YouCan\YouCanProductVariantDTO;

/**
 * Fixture trimmed from a real GET /products response — full payload also
 * carries a nested `product` copy on each variant and a long HTML
 * `description`, both irrelevant to what's being asserted here.
 */
function youCanProductFixture(): array
{
    return [
        'id' => 'b1b98088-1096-4a65-a9d8-08395a864cf3',
        'name' => 'Robe en Denim Élégante',
        'public_url' => 'https://faizat.store/products/h-oanak-fy-kl-khto',
        'thumbnail' => 'https://cdn.youcan.shop/stores/x/products/thumb_md.png',
        'description' => '<p>Some HTML</p>',
        'price' => 319,
        'visibility' => false,
        'images' => [
            ['id' => 'img-1', 'url' => 'https://cdn.youcan.shop/stores/x/products/other.png'],
        ],
        'variants' => [
            [
                'id' => '1bc91c2a-4822-42e6-aac2-9c0190486bef',
                'variations' => ['اختاري لونكِ المفضل' => 'اخضر'],
                'options' => ['اختاري لونكِ المفضل'],
                'values' => ['اخضر'],
                'price' => 319,
                'sku' => null,
                'inventory' => 7,
            ],
        ],
    ];
}

test('YouCanProductDTO uses the thumbnail field over images[0].url', function () {
    $dto = YouCanProductDTO::fromArray(youCanProductFixture());

    expect($dto->imageUrl)->toBe('https://cdn.youcan.shop/stores/x/products/thumb_md.png');
});

test('YouCanProductDTO maps visibility:false to an inactive status string', function () {
    $dto = YouCanProductDTO::fromArray(youCanProductFixture());

    expect($dto->status)->toBe('inactive');
});

test('YouCanProductDTO maps visibility:true to an active status string', function () {
    $data = youCanProductFixture();
    $data['visibility'] = true;

    $dto = YouCanProductDTO::fromArray($data);

    expect($dto->status)->toBe('active');
});

test('YouCanProductVariantDTO reads stock from the inventory field', function () {
    $dto = YouCanProductVariantDTO::fromArray(youCanProductFixture()['variants'][0]);

    expect($dto->inventoryQuantity)->toBe(7);
});

test('YouCanProductVariantDTO parses variations into option_values', function () {
    $dto = YouCanProductVariantDTO::fromArray(youCanProductFixture()['variants'][0]);

    expect($dto->optionValues)->toBe(['اختاري لونكِ المفضل' => 'اخضر']);
    expect($dto->sku)->toBeNull();
});
