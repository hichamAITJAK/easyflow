<?php

namespace App\Services\Connectivity\WooCommerce;

/**
 * WooCommerce product endpoints.
 *
 * Docs: https://woocommerce.github.io/woocommerce-rest-api-docs/#products
 */
class WooCommerceProductService extends WooCommerceHttpClient
{
    /**
     * List products.
     *
     * @param  array<string, mixed>  $query
     * @return array{items: array<int, array<string, mixed>>, totalPages: int}
     */
    public function listProducts(array $query = []): array
    {
        return $this->getPage('/products', $query);
    }

    /**
     * List a variable product's variations.
     *
     * WooCommerce keeps the sellable rows of a `type: variable` product on a
     * separate endpoint — the parent carries only their IDs — so importing a
     * variable product's real SKUs, prices and stock takes one extra call
     * per product.
     *
     * @param  array<string, mixed>  $query
     * @return array{items: array<int, array<string, mixed>>, totalPages: int}
     */
    public function listVariations(int|string $productId, array $query = []): array
    {
        return $this->getPage("/products/{$productId}/variations", $query);
    }
}
