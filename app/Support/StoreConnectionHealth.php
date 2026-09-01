<?php

namespace App\Support;

use App\Enums\StoreConnectionStatus;
use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Marks a store's connection as broken when the platform rejects its
 * credentials.
 *
 * Before this, `failed` was only ever set by Shopify's app/uninstalled
 * webhook. Every other way a connection dies — a revoked YouCan token,
 * rotated WooCommerce keys, a deleted Storeep token — left the store
 * reading "Connected" while silently ingesting nothing, which in a COD
 * business means orders stop arriving and nobody finds out until someone
 * notices the day looks quiet.
 */
class StoreConnectionHealth
{
    /**
     * HTTP statuses that mean "your credentials are no longer good".
     *
     * Deliberately narrow. A 5xx, a timeout or a 429 says the platform is
     * having a bad day, not that the merchant has to reconnect — flagging
     * those would send people to re-authorize a connection that was never
     * broken.
     */
    private const AUTH_FAILURE_STATUSES = [401, 403];

    /**
     * Whether credential rejections should currently flag the store.
     *
     * Suppressed during a store's own initial import: that sync runs
     * moments after the merchant authorized the connection, and a platform
     * that is briefly not ready (a token still propagating, scopes still
     * settling) would otherwise flag a store they just successfully
     * connected and tell them to reconnect it.
     */
    private static bool $suppressed = false;

    /**
     * Run the given work without letting it flag the store as failed.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutFlagging(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    /**
     * Flag the store if the given exception is the platform rejecting its
     * credentials. Any other failure is left alone.
     */
    public static function recordFailure(Store $store, Throwable $exception): void
    {
        if (self::$suppressed || ! self::isAuthFailure($exception)) {
            return;
        }

        self::markFailed($store);
    }

    /**
     * Whether this exception means the credentials are no longer accepted.
     */
    public static function isAuthFailure(Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && \in_array($exception->response->status(), self::AUTH_FAILURE_STATUSES, true);
    }

    /**
     * Move a store to `failed` so the UI can prompt for a reconnect.
     *
     * Written with a targeted update rather than save(): this runs from
     * queued jobs holding a Store instance that may be minutes stale, and a
     * full save would write back every other attribute along with it.
     */
    public static function markFailed(Store $store): void
    {
        if ($store->connection_status === StoreConnectionStatus::FAILED) {
            return;
        }

        Store::withoutGlobalScopes()
            ->whereKey($store->getKey())
            ->update(['connection_status' => StoreConnectionStatus::FAILED]);

        $store->setAttribute('connection_status', StoreConnectionStatus::FAILED);

        // No credentials, tokens or client PII here — just which store and
        // which business, so this stays safe to keep in the log.
        Log::warning('Store credentials rejected by platform', [
            'store_id' => $store->getKey(),
            'business_id' => $store->business_id,
        ]);
    }
}
