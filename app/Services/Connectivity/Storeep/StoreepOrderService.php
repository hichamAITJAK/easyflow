<?php

namespace App\Services\Connectivity\Storeep;

/**
 * Storeep orders endpoint.
 *
 * Requires the `orders:read` permission on the access token.
 *
 * Note: Storeep's `/orders` has no date filter of any kind — the token's
 * own data access window (last 24h / a set start date / all time, chosen
 * when the token is created) is the only server-side scoping available.
 * The connect-date cutoff is therefore enforced entirely on our side, by
 * OrderSyncService::rejectOrdersBefore().
 *
 * Docs: https://docs.storeep.com/orders
 */
class StoreepOrderService extends StoreepHttpClient
{
    /**
     * List orders.
     *
     * Supported query parameters:
     *   - market      string|string[], max 20 two-letter country codes
     *   - limit       int, 1-50 (default 20)
     *   - page        int, min 1 (default 1)
     *   - sort_field  created_at|updated_at (default created_at)
     *   - sort_order  ASC|DESC (default DESC)
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listOrders(array $query = []): array
    {
        return $this->get('/orders', $query);
    }
}
