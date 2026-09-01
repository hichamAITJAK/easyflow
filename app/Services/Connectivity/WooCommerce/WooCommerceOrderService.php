<?php

namespace App\Services\Connectivity\WooCommerce;

/**
 * WooCommerce order endpoints.
 *
 * Docs: https://woocommerce.github.io/woocommerce-rest-api-docs/#orders
 */
class WooCommerceOrderService extends WooCommerceHttpClient
{
    /**
     * List orders.
     *
     * @param  array<string, mixed>  $query
     * @return array{items: array<int, array<string, mixed>>, totalPages: int}
     */
    public function listOrders(array $query = []): array
    {
        return $this->getPage('/orders', $query);
    }

    /**
     * Fetch a single order by its WooCommerce ID.
     *
     * @return array<string, mixed>
     */
    public function getOrder(int|string $orderId): array
    {
        return $this->get("/orders/{$orderId}");
    }
}
