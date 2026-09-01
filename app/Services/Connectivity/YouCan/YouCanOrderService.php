<?php

namespace App\Services\Connectivity\YouCan;

/**
 * Handles all YouCan orders-related API calls.
 *
 * Covers: listing orders, order details (optionally including the
 * customer, variants, payment, shipping, etc.), creating orders,
 * and updating order / shipping / payment statuses.
 *
 * Docs: https://developer.youcan.shop/store-admin/orders
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the API response
 */
class YouCanOrderService extends YouCanHttpClient
{
    /**
     * Subresources the API can embed in an order via `include=`.
     */
    public const INCLUDABLE = [
        'customer', 'variants', 'payment', 'shipping',
        'discount', 'coupon', 'comments', 'refunds', 'referenced_order',
    ];

    // ----------------------------------------------------------------
    // Orders
    // ----------------------------------------------------------------

    /**
     * List orders with optional search, filters, sorting, and subresources.
     *
     * @param  array<string, mixed>  $filters  Optional query parameters:
     *                                         - q: search across ref, notes, customer name/phone/email
     *                                         - sort: "ref", "total", or "created_at"
     *                                         - filters: array of {field, value} filter pairs
     *                                         - include: comma-separated subresources (see self::INCLUDABLE)
     *                                         - page: page number
     * @return array<string, mixed>
     */
    public function listOrders(array $filters = []): array
    {
        return $this->get('/orders', $filters);
    }

    /**
     * Get details for a single order.
     *
     * @param  string  $orderId  The order ID
     * @param  array<int, string>  $include  Subresources to embed, e.g. ['customer', 'variants']
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId, array $include = []): array
    {
        return $this->get("/orders/{$orderId}", $this->includeQuery($include));
    }

    /**
     * Get an order together with its customer (including the customer's
     * address) and full line items.
     *
     * @param  string  $orderId  The order ID
     * @return array<string, mixed>
     */
    public function getOrderWithCustomer(string $orderId): array
    {
        return $this->getOrder($orderId, ['customer', 'variants', 'payment', 'shipping']);
    }

    /**
     * Create a new order.
     *
     * @param  array<string, mixed>  $data  Order payload (customer info, line items, etc.)
     * @return array<string, mixed>
     */
    public function createOrder(array $data): array
    {
        return $this->post('/orders', $data);
    }

    // ----------------------------------------------------------------
    // Order Status
    // ----------------------------------------------------------------

    /**
     * Update the general status of an order.
     *
     * @param  string  $orderId  The order ID
     * @param  string  $status  The target status slug (see listStatuses())
     * @return array<string, mixed>
     */
    public function updateOrderStatus(string $orderId, string $status): array
    {
        return $this->put("/orders/{$orderId}/status", [
            'status' => $status,
        ]);
    }

    /**
     * Update the shipping status of an order.
     *
     * @param  string  $orderId  The order ID
     * @param  string  $shippingStatus  The shipping status slug, e.g. "unfulfilled", "shipped"
     * @return array<string, mixed>
     */
    public function updateShippingStatus(string $orderId, string $shippingStatus): array
    {
        return $this->put("/orders/{$orderId}/status/shipping", [
            'status' => $shippingStatus,
        ]);
    }

    /**
     * Update the payment status of an order.
     *
     * @param  string  $orderId  The order ID
     * @param  string  $paymentStatus  The payment status slug, e.g. "pending", "paid", "refunded"
     * @return array<string, mixed>
     */
    public function updatePaymentStatus(string $orderId, string $paymentStatus): array
    {
        return $this->put("/orders/{$orderId}/status/payment", [
            'status' => $paymentStatus,
        ]);
    }

    // ----------------------------------------------------------------
    // Order Statuses (store-level settings)
    // ----------------------------------------------------------------

    /**
     * List all available order statuses (payment, shipping, and order)
     * defined for the store.
     *
     * @return array<string, mixed>
     */
    public function listStatuses(): array
    {
        return $this->get('/orders/settings');
    }

    /**
     * Update store-level order status settings.
     *
     * @param  array<string, mixed>  $data  Status settings payload
     * @return array<string, mixed>
     */
    public function updateStoreStatusSettings(array $data): array
    {
        return $this->put('/orders/settings', $data);
    }

    /**
     * Build the `include` query parameter from a list of subresource names.
     *
     * @param  array<int, string>  $include
     * @return array<string, mixed>
     */
    private function includeQuery(array $include): array
    {
        return $include === [] ? [] : ['include' => implode(',', $include)];
    }
}
