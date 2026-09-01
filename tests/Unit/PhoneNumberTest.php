<?php

use App\Support\PhoneNumber;

test('formats a local 0X number to itself', function () {
    expect(PhoneNumber::format('0600000000'))->toBe('0600000000');
});

test('formats an international +212 number by dropping the country code', function () {
    expect(PhoneNumber::format('+212600000000'))->toBe('0600000000');
});

test('formats a 00212 international prefix number', function () {
    expect(PhoneNumber::format('00212600000000'))->toBe('0600000000');
});

test('strips spaces, dashes, and parentheses before formatting', function () {
    expect(PhoneNumber::format('06 00-00 (00) 00'))->toBe('0600000000');
});

test('takes only the last 9 digits when given extra leading digits', function () {
    expect(PhoneNumber::format('999600000000'))->toBe('0600000000');
});

test('returns null for a null number', function () {
    expect(PhoneNumber::format(null))->toBeNull();
});

test('returns null for an empty string', function () {
    expect(PhoneNumber::format(''))->toBeNull();
});

test('returns null when fewer than 9 digits remain after stripping non-digits', function () {
    expect(PhoneNumber::format('12345678'))->toBeNull();
});

test('returns null for a string with no digits at all', function () {
    expect(PhoneNumber::format('not-a-number'))->toBeNull();
});

test('formats a landline-style 05 number the same as a mobile number', function () {
    expect(PhoneNumber::format('0522000000'))->toBe('0522000000');
});
