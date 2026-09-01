<?php

namespace App\Services\Connectivity\Lightfunnels;

/**
 * Handles all Lightfunnels product-related GraphQL queries and mutations.
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the GraphQL response
 */
class LightfunnelsProductService extends LightfunnelsHttpClient
{
    /**
     * List products with pagination and an optional filter query.
     *
     * @param  int  $first  Number of products to return
     * @param  string  $after  Cursor for pagination (from previous pageInfo.endCursor)
     * @param  string  $query  Filter string, e.g. "order_by:id"
     * @return array<string, mixed>
     */
    public function listProducts(int $first = 10, string $after = '', string $query = 'order_by:id'): array
    {
        $gql = <<<'GQL'
        query productsQuery($first: Int, $after: String, $query: String!) {
            products(query: $query, after: $after, first: $first) {
                edges {
                    node {
                        id
                        _id
                        title
                        slug
                        description
                        price
                        compare_at_price
                        sku
                        inventory_quantity
                        images {
                            path(version: version1)
                        }
                        thumbnail {
                            path(version: version1)
                        }
                        variants {
                            id
                            _id
                            title
                            price
                            compare_at_price
                            sku
                            inventory_quantity
                            labeldOptions {
                                label
                                value
                            }
                        }
                        tags {
                            title
                        }
                        stores {
                            id
                            uid
                        }
                        created_at
                        updated_at
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
     * Retrieve a single product by its id.
     *
     * @return array<string, mixed>
     */
    public function getProduct(string $productId): array
    {
        $gql = <<<'GQL'
        query productQuery($id: ID!) {
            node(id: $id) {
                ... on Product {
                    id
                    _id
                    title
                    slug
                    description
                    price
                    compare_at_price
                    sku
                    inventory_quantity
                    images {
                        path(version: version1)
                    }
                    thumbnail {
                        path(version: version1)
                    }
                    variants {
                        id
                        _id
                        title
                        price
                        compare_at_price
                        sku
                        inventory_quantity
                        labeldOptions {
                            label
                            value
                        }
                    }
                    tags {
                        title
                    }
                    stores {
                        id
                        uid
                    }
                    created_at
                    updated_at
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['id' => $productId]);
    }

    /**
     * Create a new product.
     *
     * @param  array<string, mixed>  $node  InputProduct fields: title, options, variants, price, etc.
     * @return array<string, mixed>
     */
    public function createProduct(array $node): array
    {
        $gql = <<<'GQL'
        mutation newProductMutation($node: InputProduct!) {
            createProduct(node: $node) {
                id
                _id
                title
                price
            }
        }
        GQL;

        return $this->graphql($gql, ['node' => $node]);
    }

    /**
     * Update an existing product.
     *
     * @param  string  $productId  The product id to update
     * @param  array<string, mixed>  $node  InputUpdateProduct fields to update
     * @return array<string, mixed>
     */
    public function updateProduct(string $productId, array $node): array
    {
        $gql = <<<'GQL'
        mutation newProductUpdateMutation($node: InputUpdateProduct!, $id: ID!) {
            updateProduct(node: $node, id: $id) {
                id
                _id
                title
                price
            }
        }
        GQL;

        return $this->graphql($gql, ['node' => $node, 'id' => $productId]);
    }

    /**
     * Delete one or more products by id.
     *
     * @param  array<int, string>  $productIds  List of product ids to delete
     * @return array<string, mixed>
     */
    public function deleteProducts(array $productIds): array
    {
        $gql = <<<'GQL'
        mutation deleteProductsMutation($items: [ID!]!) {
            deleteProducts(items: $items)
        }
        GQL;

        return $this->graphql($gql, ['items' => $productIds]);
    }

    /**
     * Add existing products to a store.
     *
     * @param  string  $storeId  The store id to add products to
     * @param  array<int, string>  $productIds  List of product ids to add
     * @return array<string, mixed>
     */
    public function addProductsToStore(string $storeId, array $productIds): array
    {
        $gql = <<<'GQL'
        mutation addProductsToStore($node: AddProductsToStoreInput!, $id: ID!) {
            addProductsToStore(id: $id, node: $node) {
                id
                products(cursor: null) {
                    title
                    price
                    link
                    cursor
                }
            }
        }
        GQL;

        return $this->graphql($gql, [
            'id' => $storeId,
            'node' => ['products_uids' => $productIds],
        ]);
    }
}
