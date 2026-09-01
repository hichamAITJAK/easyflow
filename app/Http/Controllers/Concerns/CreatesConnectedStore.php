<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Scopes\BusinessScope;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Shared "create the store row after a successful OAuth callback" step for
 * every ecom platform connection controller.
 *
 * A platform's external store id is unique per platform (see
 * stores_platform_id_external_store_id_unique), so running the connect flow
 * for a store that already exists collides on that constraint. Whether that
 * is an error depends on who owns the existing row:
 *
 * - Same business: this is a *reconnect*. Credentials are refreshed on the
 *   existing row rather than a new one being created, so the store keeps its
 *   id and everything hanging off it — products, orders, commission rules,
 *   agent scopes, stats history. This is the only way to recover a store
 *   whose token was revoked or expired; without it the merchant's sole
 *   option is deleting the store, which destroys all of that.
 * - Another business: a genuine conflict, and it stays an error.
 */
trait CreatesConnectedStore
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createStoreOrRedirectBack(array $attributes): Store|RedirectResponse
    {
        $existing = $this->existingStoreFor($attributes);

        if ($existing !== null) {
            return $this->reconnectStore($existing, $attributes);
        }

        if (empty($attributes['slug'])) {
            $attributes['slug'] = $this->uniqueStoreSlug($attributes['business_id'], $attributes['name']);
        }

        try {
            return Store::create($attributes);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            // Lost a race against a concurrent connect for the same store, or
            // the row belongs to another business. Re-read before deciding:
            // the first case is a reconnect, the second stays an error.
            $existing = $this->existingStoreFor($attributes);

            if ($existing !== null) {
                return $this->reconnectStore($existing, $attributes);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => __('This store is already connected to another account.')]);

            return to_route('stores.create');
        }
    }

    /**
     * The caller's own store for this platform + external id, if any.
     *
     * Scoped to the business on purpose: another tenant's row must not be
     * found here, or reconnecting would hand their store over.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function existingStoreFor(array $attributes): ?Store
    {
        if (($attributes['external_store_id'] ?? null) === null) {
            return null;
        }

        return Store::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $attributes['business_id'])
            ->where('platform_id', $attributes['platform_id'])
            ->where('external_store_id', $attributes['external_store_id'])
            ->first();
    }

    /**
     * Refresh an existing store's credentials and connection state.
     *
     * The merchant's own edits are preserved: `name` and `slug` are how the
     * store is identified throughout the app (and the slug appears in
     * webhook URLs), so a reconnect must not silently rename a store back to
     * whatever the platform currently calls it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function reconnectStore(Store $store, array $attributes): Store
    {
        $store->forceFill(Arr::except($attributes, [
            'business_id',
            'platform_id',
            'external_store_id',
            'name',
            'slug',
        ]))->save();

        return $store;
    }

    /**
     * Generate a slug for the store, unique within the business, falling
     * back to a numeric suffix when the platform didn't provide one and the
     * name-derived slug collides with an existing store.
     */
    private function uniqueStoreSlug(int $businessId, string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $suffix = 2;

        while (Store::where('business_id', $businessId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
