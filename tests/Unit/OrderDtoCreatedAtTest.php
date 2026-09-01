<?php

use App\DTOs\Lightfunnels\LightfunnelsOrderDTO;
use App\DTOs\Shopify\ShopifyOrderDTO;
use App\DTOs\YouCan\YouCanOrderDTO;

test('ShopifyOrderDTO carries the GraphQL createdAt string through to toArray as created_at', function () {
    $dto = ShopifyOrderDTO::fromArray([
        'id' => 'gid://shopify/Order/1',
        'name' => '#1001',
        'createdAt' => '2024-01-15T10:30:00Z',
    ]);

    expect($dto->createdAt)->toBe('2024-01-15T10:30:00Z');
    expect($dto->toArray()['created_at'])->toBe('2024-01-15T10:30:00Z');
});

test('YouCanOrderDTO carries the raw Unix epoch int through to toArray as created_at', function () {
    $dto = YouCanOrderDTO::fromArray([
        'id' => '2250ad72-45b5-11e9-b473-080027e8bf1b',
        'ref' => '009',
        'created_at' => 1552497987,
    ]);

    expect($dto->createdAt)->toBe(1552497987);
    expect($dto->toArray()['created_at'])->toBe(1552497987);
});

test('LightfunnelsOrderDTO carries the pre-formatted ISO 8601 string through to toArray as created_at', function () {
    $dto = LightfunnelsOrderDTO::fromArray([
        'id' => 'order_123',
        'name' => '#1001',
        'created_at' => '2024-01-15T10:30:00Z',
    ]);

    expect($dto->createdAt)->toBe('2024-01-15T10:30:00Z');
    expect($dto->toArray()['created_at'])->toBe('2024-01-15T10:30:00Z');
});
