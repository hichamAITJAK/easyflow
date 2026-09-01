<?php

use App\Support\TrackingNumberExtractor;

test('a bare tracking number is returned as-is — the common case', function () {
    expect(TrackingNumberExtractor::extract('DH123456'))->toBe('DH123456');
});

test('surrounding whitespace is stripped', function () {
    expect(TrackingNumberExtractor::extract("  DH123456\n"))->toBe('DH123456');
});

test('a tracking URL yields its last path segment', function () {
    expect(TrackingNumberExtractor::extract('https://app.example.ma/parcel/DH123456'))->toBe('DH123456');
});

test('a trailing slash on a tracking URL does not swallow the segment', function () {
    expect(TrackingNumberExtractor::extract('https://app.example.ma/parcel/DH123456/'))->toBe('DH123456');
});

test('a known tracking query parameter wins over the path segment', function () {
    expect(TrackingNumberExtractor::extract('https://client.example.ma/pdf-delivery-note?dn-ref=OZ-99887'))
        ->toBe('OZ-99887');
});

test('query keys are tried most-specific first when a URL carries several', function () {
    expect(TrackingNumberExtractor::extract('https://example.ma/t?ref=GENERIC&dn-ref=SPECIFIC'))
        ->toBe('SPECIFIC');
});

test('an empty query parameter is skipped rather than returned', function () {
    expect(TrackingNumberExtractor::extract('https://example.ma/parcel/DH123456?tracking='))
        ->toBe('DH123456');
});

test('a bare domain with nothing to extract falls back to the raw value', function () {
    expect(TrackingNumberExtractor::extract('https://example.ma'))->toBe('https://example.ma');
});

test('a value that merely contains a slash is not treated as a URL', function () {
    expect(TrackingNumberExtractor::extract('DH/123456'))->toBe('DH/123456');
});

test('an empty scan yields an empty string rather than throwing', function () {
    expect(TrackingNumberExtractor::extract('   '))->toBe('');
});
