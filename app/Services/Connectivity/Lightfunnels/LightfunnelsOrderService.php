<?php

namespace App\Services\Connectivity\Lightfunnels;

/**
 * Handles all Lightfunnels order-related GraphQL queries and mutations.
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the GraphQL response
 */
class LightfunnelsOrderService extends LightfunnelsHttpClient
{
    /**
     * List orders with pagination and an optional filter query.
     *
     * @param  int  $first  Number of orders to return
     * @param  string  $after  Cursor for pagination (from previous pageInfo.endCursor)
     * @param  string  $query  Filter string, e.g. "order_by:id"
     * @return array<string, mixed>
     */
    public function listOrders(int $first = 10, string $after = '', string $query = 'order_by:id'): array
    {
        $gql = <<<'GQL'
        query ordersQuery($first: Int, $after: String, $query: String!) {
            orders(query: $query, after: $after, first: $first) {
                edges {
                    node {
                        id
                        _id
                        name
                        total
                        subtotal
                        discount_value
                        shipping
                        fulfillment_status
                        financial_status
                        email
                        phone
                        customer {
                            id
                            full_name
                            email
                            phone
                        }
                        shipping_address {
                            first_name
                            last_name
                            line1
                            line2
                            city
                            state
                            country
                            zip
                            phone
                        }
                        items {
                            __typename
                            ... on VariantSnapshot {
                                product_uid: product_id
                                id
                                _id
                                title
                                price
                                sku
                                fulfillment_status
                                carrier
                                tracking_number
                                tracking_link
                                options {
                                    label
                                    value
                                }
                            }
                            ... on OrderBumpSnapshot {
                                product_uid: product_id
                                id
                                _id
                                title
                                price
                                fulfillment_status
                                carrier
                                tracking_number
                                tracking_link
                            }
                        }
                        notes
                        cancelled_at
                        created_at
                        test
                    }
                    cursor
                }
                pageInfo {
                    endCursor
                    hasNextPage
                }
            }
        }
        GQL;

        return $this->graphql($gql, [
            'query' => $query,
            'after' => $after,
            'first' => $first,
        ]);
    }

    /**
     * Retrieve a single order by its id, including its line items.
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $gql = <<<'GQL'
        query viewOrderQuery($id: ID!) {
            node(id: $id) {
                ... on Order {
                    id
                    _id
                    name
                    total
                    subtotal
                    discount_value
                    shipping
                    fulfillment_status
                    financial_status
                    email
                    phone
                    customer {
                        id
                        full_name
                        email
                        phone
                    }
                    shipping_address {
                        first_name
                        last_name
                        line1
                        line2
                        city
                        state
                        country
                        zip
                        phone
                    }
                    notes
                    cancelled_at
                    created_at(format: "YYYY-MM-DDTHH:mm:ssZ")
                    test
                    items {
                        __typename
                        ... on VariantSnapshot {
                            product_uid: product_id
                            id
                            _id
                            title
                            price
                            sku
                            fulfillment_status
                            carrier
                            tracking_number
                            tracking_link
                            options {
                                label
                                value
                            }
                        }
                        ... on OrderBumpSnapshot {
                            product_uid: product_id
                            id
                            _id
                            title
                            price
                            fulfillment_status
                            carrier
                            tracking_number
                            tracking_link
                        }
                    }
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['id' => $orderId]);
    }

    /**
     * Update an order's editable fields (notes, tags, etc.).
     *
     * @param  string  $orderId  The order id to update
     * @param  array<string, mixed>  $node  InputOrder fields to update
     * @return array<string, mixed>
     */
    public function updateOrder(string $orderId, array $node): array
    {
        $gql = <<<'GQL'
        mutation updateOrderMutation($node: InputOrder!, $id: ID!) {
            updateOrder(node: $node, id: $id) {
                id
                _id
                name
                total
                subtotal
                notes
            }
        }
        GQL;

        return $this->graphql($gql, ['node' => $node, 'id' => $orderId]);
    }

    /**
     * Cancel an order.
     *
     * @return array<string, mixed>
     */
    public function cancelOrder(
        string $orderId,
        string $reason = '',
        bool $notifyCustomer = false,
        bool $refund = false
    ): array {
        $gql = <<<'GQL'
        mutation cancelOrderMutation($id: ID!, $reason: String!, $notifyCustomer: Boolean!, $refund: Boolean!) {
            cancelOrder(id: $id, reason: $reason, notifyCustomer: $notifyCustomer, refund: $refund) {
                id
                cancelled_at
                refunded_amount
                paid_by_customer
                net_payment
                refundable
            }
        }
        GQL;

        return $this->graphql($gql, [
            'id' => $orderId,
            'reason' => $reason,
            'notifyCustomer' => $notifyCustomer,
            'refund' => $refund,
        ]);
    }

    /**
     * Mark an order's items as fulfilled with tracking info.
     *
     * @param  array<int, string>  $itemIds  List of order item ids being fulfilled
     * @return array<string, mixed>
     */
    public function fulfillItems(
        string $orderId,
        array $itemIds,
        string $trackingNumber = '',
        string $trackingLink = '',
        ?string $carrier = null
    ): array {
        $gql = <<<'GQL'
        mutation itemsFulfillMutation($items: [ID!]!, $trackingNumber: String!, $trackingLink: String!, $id: ID!, $carrier: String) {
            fulfillItems(items: $items, trackingNumber: $trackingNumber, trackingLink: $trackingLink, id: $id, carrier: $carrier) {
                id
                fulfillment_status
            }
        }
        GQL;

        return $this->graphql($gql, [
            'items' => $itemIds,
            'trackingNumber' => $trackingNumber,
            'trackingLink' => $trackingLink,
            'id' => $orderId,
            'carrier' => $carrier,
        ]);
    }

    /**
     * Mark an order as paid.
     *
     * @return array<string, mixed>
     */
    public function markAsPaid(string $orderId): array
    {
        $gql = <<<'GQL'
        mutation markOrderAsPaid($id: ID!) {
            markAsPaid(id: $id) {
                id
                _id
                total
            }
        }
        GQL;

        return $this->graphql($gql, ['id' => $orderId]);
    }
}
