<?php

use App\Enums\StoreConnectionStatus;
use App\Enums\UserRole;
use App\Support\StoreConnectionHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

uses(RefreshDatabase::class);

function requestExceptionWithStatus(int $status): RequestException
{
    return new RequestException(
        new Response(new GuzzleHttp\Psr7\Response($status))
    );
}

test('a 401 from the platform marks the store failed', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id);

    StoreConnectionHealth::recordFailure($store, requestExceptionWithStatus(401));

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::FAILED);
});

test('a 403 from the platform marks the store failed', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id);

    StoreConnectionHealth::recordFailure($store, requestExceptionWithStatus(403));

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::FAILED);
});

test('a platform outage does not mark the store failed', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id);

    // A 500 means the platform is having a bad day, not that the merchant
    // has to re-authorize — flagging it would send them to fix nothing.
    StoreConnectionHealth::recordFailure($store, requestExceptionWithStatus(500));

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
});

test('rate limiting does not mark the store failed', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id);

    StoreConnectionHealth::recordFailure($store, requestExceptionWithStatus(429));

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
});

test('a non-http failure does not mark the store failed', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id);

    StoreConnectionHealth::recordFailure($store, new RuntimeException('boom'));

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
});

test('a credential rejection during the initial import does not flag the store', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id);

    // The merchant authorized this seconds ago. A platform that is not
    // ready for API calls yet must not produce a "reconnect this store"
    // prompt for a connection that just succeeded.
    StoreConnectionHealth::withoutFlagging(function () use ($store) {
        StoreConnectionHealth::recordFailure($store, requestExceptionWithStatus(401));
    });

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::CONNECTED);
});

test('flagging resumes after the suppressed block ends', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id);

    StoreConnectionHealth::withoutFlagging(fn () => null);

    StoreConnectionHealth::recordFailure($store, requestExceptionWithStatus(401));

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::FAILED);
});

test('suppression is lifted even when the work throws', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $store = makeStore($admin->business_id);

    try {
        StoreConnectionHealth::withoutFlagging(function () {
            throw new RuntimeException('sync blew up');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    StoreConnectionHealth::recordFailure($store, requestExceptionWithStatus(401));

    expect($store->fresh()->connection_status)->toBe(StoreConnectionStatus::FAILED);
});
