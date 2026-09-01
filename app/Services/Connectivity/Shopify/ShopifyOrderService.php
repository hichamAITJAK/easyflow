<?php

namespace App\Services\Connectivity\Shopify;

/**
 * Handles all Shopify order-related GraphQL queries and mutations.
 *
 * Uses the `orders` query and order mutations from the Shopify Admin GraphQL API.
 *
 * Docs: https://shopify.dev/docs/api/admin-graphql/latest/queries/orders
 *
 * Required scopes: read_orders for the queries, plus read_customers because
 * Order.customer resolves a Customer object which carries its own scope.
 * The mutations below additionally need write_orders — none of them are
 * called yet, so write_orders is deliberately not requested.
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the GraphQL response
 */
class ShopifyOrderService extends ShopifyHttpClient
{
    // ----------------------------------------------------------------
    // Queries
    // ----------------------------------------------------------------

    /**
     * List orders with optional filters.
     *
     * @param  int  $first  Number of orders to return (max 250)
     * @param  string  $after  Cursor for pagination (from previous pageInfo.endCursor)
     * @param  string  $query  GraphQL filter string, e.g. "financial_status:paid created_at:>2024-01-01"
     * @param  string  $sortKey  Sort key: CREATED_AT, UPDATED_AT, TOTAL_PRICE, ID, etc.
     * @param  bool  $reverse  Whether to reverse the sort order
     * @return array<string, mixed>
     */
    public function listOrders(
        int $first = 10,
        string $after = '',
        string $query = '',
        string $sortKey = 'CREATED_AT',
        bool $reverse = true
    ): array {
        $gql = <<<'GQL'
        query($first: Int!, $after: String, $query: String, $sortKey: OrderSortKeys, $reverse: Boolean) {
            orders(first: $first, after: $after, query: $query, sortKey: $sortKey, reverse: $reverse) {
                pageInfo {
                    hasNextPage
                    hasPreviousPage
                    startCursor
                    endCursor
                }
                edges {
                    node {
                        id
                        name
                        createdAt
                        updatedAt
                        displayFinancialStatus
                        displayFulfillmentStatus
                        totalPriceSet {
                            shopMoney { amount currencyCode }
                        }
                        subtotalPriceSet {
                            shopMoney { amount currencyCode }
                        }
                        totalShippingPriceSet {
                            shopMoney { amount currencyCode }
                        }
                        customer {
                            id
                            email
                            firstName
                            lastName
                            phone
                        }
                        shippingAddress {
                            address1
                            address2
                            city
                            province
                            country
                            zip
                            phone
                            firstName
                            lastName
                        }
                        lineItems(first: 50) {
                            edges {
                                node {
                                    id
                                    title
                                    quantity
                                    sku
                                    variantTitle
                                    originalUnitPriceSet {
                                        shopMoney { amount currencyCode }
                                    }
                                }
                            }
                        }
                        tags
                        note
                    }
                }
            }
        }
        GQL;

        return $this->graphql($gql, [
            'first' => $first,
            'after' => $after ?: null,
            'query' => $query ?: null,
            'sortKey' => $sortKey,
            'reverse' => $reverse,
        ]);
    }

    /**
     * Retrieve a single order by its GraphQL global ID.
     *
     * @param  string  $orderId  The Shopify global ID, e.g. "gid://shopify/Order/1234567890"
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $gql = <<<'GQL'
        query($id: ID!) {
            order(id: $id) {
                id
                name
                createdAt
                updatedAt
                cancelledAt
                closedAt
                displayFinancialStatus
                displayFulfillmentStatus
                note
                tags
                email
                phone
                totalPriceSet {
                    shopMoney { amount currencyCode }
                }
                subtotalPriceSet {
                    shopMoney { amount currencyCode }
                }
                totalTaxSet {
                    shopMoney { amount currencyCode }
                }
                totalShippingPriceSet {
                    shopMoney { amount currencyCode }
                }
                totalDiscountsSet {
                    shopMoney { amount currencyCode }
                }
                customer {
                    id
                    email
                    firstName
                    lastName
                    phone
                    numberOfOrders
                    amountSpent { amount currencyCode }
                }
                shippingAddress {
                    address1
                    address2
                    city
                    province
                    country
                    zip
                    phone
                    firstName
                    lastName
                    company
                }
                lineItems(first: 100) {
                    edges {
                        node {
                            id
                            title
                            quantity
                            sku
                            variantTitle
                            requiresShipping
                            taxable
                            originalUnitPriceSet {
                                shopMoney { amount currencyCode }
                            }
                            discountedUnitPriceSet {
                                shopMoney { amount currencyCode }
                            }
                            product { id title }
                            variant { id title sku inventoryQuantity }
                        }
                    }
                }
                fulfillments {
                    id
                    status
                    createdAt
                    updatedAt
                    trackingInfo { number url company }
                }
                transactions {
                    id
                    kind
                    status
                    amountSet { shopMoney { amount currencyCode } }
                    createdAt
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['id' => $orderId]);
    }

    // ----------------------------------------------------------------
    // Mutations
    // ----------------------------------------------------------------

    /**
     * Update an order's tags, note, or other editable fields.
     *
     * @param  string  $orderId  The Shopify global ID, e.g. "gid://shopify/Order/1234567890"
     * @param  array<string, mixed>  $input  Fields to update: tags, note, email, shippingAddress, etc.
     * @return array<string, mixed>
     */
    public function updateOrder(string $orderId, array $input): array
    {
        $gql = <<<'GQL'
        mutation($input: OrderInput!) {
            orderUpdate(input: $input) {
                order {
                    id
                    name
                    tags
                    note
                    updatedAt
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        return $this->graphql($gql, [
            'input' => array_merge(['id' => $orderId], $input),
        ]);
    }

    /**
     * Cancel an order.
     *
     * @param  string  $orderId  The Shopify global ID
     * @param  string  $reason  Cancel reason: CUSTOMER, FRAUD, INVENTORY, DECLINED, OTHER
     * @param  bool  $notifyCustomer  Send cancellation email to customer
     * @param  bool  $refund  Refund the order on cancellation
     * @return array<string, mixed>
     */
    public function cancelOrder(
        string $orderId,
        string $reason = 'OTHER',
        bool $notifyCustomer = false,
        bool $refund = false
    ): array {
        $gql = <<<'GQL'
        mutation($orderId: ID!, $reason: OrderCancelReason!, $notifyCustomer: Boolean!, $refund: Boolean!) {
            orderCancel(orderId: $orderId, reason: $reason, notifyCustomer: $notifyCustomer, refund: $refund) {
                job {
                    id
                    done
                }
                orderCancelUserErrors {
                    field
                    message
                    code
                }
            }
        }
        GQL;

        return $this->graphql($gql, [
            'orderId' => $orderId,
            'reason' => $reason,
            'notifyCustomer' => $notifyCustomer,
            'refund' => $refund,
        ]);
    }

    /**
     * Close an order (archive it).
     *
     * @param  string  $orderId  The Shopify global ID
     * @return array<string, mixed>
     */
    public function closeOrder(string $orderId): array
    {
        $gql = <<<'GQL'
        mutation($input: OrderCloseInput!) {
            orderClose(input: $input) {
                order {
                    id
                    name
                    closedAt
                    displayFulfillmentStatus
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['input' => ['id' => $orderId]]);
    }

    /**
     * Mark a closed order as open again.
     *
     * @param  string  $orderId  The Shopify global ID
     * @return array<string, mixed>
     */
    public function openOrder(string $orderId): array
    {
        $gql = <<<'GQL'
        mutation($input: OrderOpenInput!) {
            orderOpen(input: $input) {
                order {
                    id
                    name
                    closedAt
                    displayFulfillmentStatus
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['input' => ['id' => $orderId]]);
    }

    /**
     * Create a fulfillment for an order.
     *
     * @param  array<string, mixed>  $fulfillment  Fulfillment input per the FulfillmentInput schema
     * @return array<string, mixed>
     */
    public function createFulfillment(array $fulfillment): array
    {
        $gql = <<<'GQL'
        mutation($fulfillment: FulfillmentInput!) {
            fulfillmentCreate(fulfillment: $fulfillment) {
                fulfillment {
                    id
                    status
                    createdAt
                    trackingInfo { number url company }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['fulfillment' => $fulfillment]);
    }
}
