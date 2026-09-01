<?php

use App\Enums\EcomPlatform;
use App\Services\Operations\Orders\OrderedAtParser;

test('parses a Shopify ISO 8601 timestamp', function () {
    $parsed = OrderedAtParser::parse(EcomPlatform::SHOPIFY->value, '2024-01-15T10:30:00Z');

    expect($parsed->toDateTimeString())->toBe('2024-01-15 10:30:00');
});

test('parses a YouCan Unix epoch integer', function () {
    $parsed = OrderedAtParser::parse(EcomPlatform::YOUCAN->value, 1552497987);

    expect($parsed->getTimestamp())->toBe(1552497987);
});

test('parses a YouCan Unix epoch that arrived as a numeric string', function () {
    $parsed = OrderedAtParser::parse(EcomPlatform::YOUCAN->value, '1552497987');

    expect($parsed->getTimestamp())->toBe(1552497987);
});

test('parses a Lightfunnels ISO 8601 timestamp', function () {
    $parsed = OrderedAtParser::parse(EcomPlatform::LIGHTFUNNELS->value, '2024-01-15T10:30:00Z');

    expect($parsed->toDateTimeString())->toBe('2024-01-15 10:30:00');
});

test('returns null for a null or empty raw value', function () {
    expect(OrderedAtParser::parse(EcomPlatform::SHOPIFY->value, null))->toBeNull();
    expect(OrderedAtParser::parse(EcomPlatform::SHOPIFY->value, ''))->toBeNull();
});

test('returns null instead of throwing on an unparseable value', function () {
    expect(OrderedAtParser::parse(EcomPlatform::SHOPIFY->value, 'not-a-date'))->toBeNull();
});

test('falls back to generic string parsing when the platform slug is unknown or missing', function () {
    $parsed = OrderedAtParser::parse(null, '2024-01-15T10:30:00Z');

    expect($parsed->toDateTimeString())->toBe('2024-01-15 10:30:00');
});
