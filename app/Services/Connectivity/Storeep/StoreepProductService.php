<?php

namespace App\Services\Connectivity\Storeep;

/**
 * Storeep products endpoint.
 *
 * Requires the `products:read` permission on the access token.
 *
 * Docs: https://docs.storeep.com/products
 */
class StoreepProductService extends StoreepHttpClient
{
    /**
     * List products.
     *
     * Supported query parameters:
     *   - search      string, max 255 chars, filters by product name
     *   - market      string|string[], max 20 two-letter country codes
     *   - limit       int, 1-50 (default 20)
     *   - page        int, min 1 (default 1)
     *   - sort_field  created_at|updated_at (default created_at)
     *   - sort_order  ASC|DESC (default DESC)
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listProducts(array $query = []): array
    {
        return $this->get('/products', $query);
    }
}
