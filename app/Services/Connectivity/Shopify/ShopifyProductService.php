<?php

namespace App\Services\Connectivity\Shopify;

/**
 * Handles all Shopify product-related GraphQL queries and mutations.
 *
 * Uses the `products` query and product mutations from the Shopify Admin GraphQL API.
 *
 * Docs: https://shopify.dev/docs/api/admin-graphql/latest/queries/products
 *
 * Required scopes: read_products, write_products
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the GraphQL response
 */
class ShopifyProductService extends ShopifyHttpClient
{
    // ----------------------------------------------------------------
    // Queries
    // ----------------------------------------------------------------

    /**
     * List products with optional filters and pagination.
     *
     * @param  int  $first  Number of products to return (max 250)
     * @param  string  $after  Cursor for pagination
     * @param  string  $query  Filter string, e.g. "status:ACTIVE vendor:Nike"
     * @param  string  $sortKey  TITLE, CREATED_AT, UPDATED_AT, INVENTORY_TOTAL, etc.
     * @param  bool  $reverse  Reverse sort order
     * @return array<string, mixed>
     */
    public function listProducts(
        int $first = 10,
        string $after = '',
        string $query = '',
        string $sortKey = 'TITLE',
        bool $reverse = false
    ): array {
        $gql = <<<'GQL'
        query($first: Int!, $after: String, $query: String, $sortKey: ProductSortKeys, $reverse: Boolean) {
            products(first: $first, after: $after, query: $query, sortKey: $sortKey, reverse: $reverse) {
                pageInfo {
                    hasNextPage
                    hasPreviousPage
                    startCursor
                    endCursor
                }
                edges {
                    node {
                        id
                        title
                        handle
                        onlineStoreUrl
                        onlineStorePreviewUrl
                        status
                        vendor
                        productType
                        tags
                        createdAt
                        updatedAt
                        publishedAt
                        totalInventory
                        featuredImage {
                            url
                            altText
                        }
                        priceRangeV2 {
                            minVariantPrice { amount currencyCode }
                            maxVariantPrice { amount currencyCode }
                        }
                        variants(first: 10) {
                            edges {
                                node {
                                    id
                                    title
                                    sku
                                    price
                                    inventoryQuantity
                                    availableForSale
                                    selectedOptions { name value }
                                }
                            }
                        }
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
     * Retrieve a single product by its GraphQL global ID.
     *
     * @param  string  $productId  e.g. "gid://shopify/Product/1234567890"
     * @return array<string, mixed>
     */
    public function getProduct(string $productId): array
    {
        $gql = <<<'GQL'
        query($id: ID!) {
            product(id: $id) {
                id
                title
                handle
                onlineStoreUrl
                onlineStorePreviewUrl
                description
                descriptionHtml
                status
                vendor
                productType
                tags
                createdAt
                updatedAt
                publishedAt
                totalInventory
                hasOnlyDefaultVariant
                featuredImage {
                    id
                    url
                    altText
                    width
                    height
                }
                images(first: 20) {
                    edges {
                        node {
                            id
                            url
                            altText
                            width
                            height
                        }
                    }
                }
                options {
                    id
                    name
                    values
                }
                variants(first: 100) {
                    edges {
                        node {
                            id
                            title
                            sku
                            price
                            compareAtPrice
                            inventoryQuantity
                            availableForSale
                            weight
                            weightUnit
                            requiresShipping
                            taxable
                            selectedOptions { name value }
                        }
                    }
                }
                priceRangeV2 {
                    minVariantPrice { amount currencyCode }
                    maxVariantPrice { amount currencyCode }
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['id' => $productId]);
    }

    /**
     * List products belonging to a specific collection.
     *
     * @param  string  $collectionId  e.g. "gid://shopify/Collection/1234567890"
     * @param  int  $first  Number of products to return
     * @param  string  $after  Cursor for pagination
     * @return array<string, mixed>
     */
    public function listProductsByCollection(string $collectionId, int $first = 10, string $after = ''): array
    {
        $gql = <<<'GQL'
        query($id: ID!, $first: Int!, $after: String) {
            collection(id: $id) {
                id
                title
                products(first: $first, after: $after) {
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                    edges {
                        node {
                            id
                            title
                            handle
                            status
                            totalInventory
                            priceRangeV2 {
                                minVariantPrice { amount currencyCode }
                            }
                        }
                    }
                }
            }
        }
        GQL;

        return $this->graphql($gql, [
            'id' => $collectionId,
            'first' => $first,
            'after' => $after ?: null,
        ]);
    }

    // ----------------------------------------------------------------
    // Mutations
    // ----------------------------------------------------------------

    /**
     * Create a new product.
     *
     * @param  array<string, mixed>  $input  ProductInput fields: title, descriptionHtml, vendor, productType,
     *                                       tags, status, images, variants, options, etc.
     * @return array<string, mixed>
     */
    public function createProduct(array $input): array
    {
        $gql = <<<'GQL'
        mutation($input: ProductInput!) {
            productCreate(input: $input) {
                product {
                    id
                    title
                    handle
                    status
                    createdAt
                    variants(first: 10) {
                        edges { node { id title sku price } }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['input' => $input]);
    }

    /**
     * Update an existing product.
     *
     * @param  string  $productId  e.g. "gid://shopify/Product/1234567890"
     * @param  array<string, mixed>  $input  ProductInput fields to update
     * @return array<string, mixed>
     */
    public function updateProduct(string $productId, array $input): array
    {
        $gql = <<<'GQL'
        mutation($input: ProductInput!) {
            productUpdate(input: $input) {
                product {
                    id
                    title
                    handle
                    status
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
            'input' => array_merge(['id' => $productId], $input),
        ]);
    }

    /**
     * Delete a product by its global ID.
     *
     * @param  string  $productId  e.g. "gid://shopify/Product/1234567890"
     * @return array<string, mixed>
     */
    public function deleteProduct(string $productId): array
    {
        $gql = <<<'GQL'
        mutation($input: ProductDeleteInput!) {
            productDelete(input: $input) {
                deletedProductId
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['input' => ['id' => $productId]]);
    }

    /**
     * Update a product variant.
     *
     * @param  string  $variantId  e.g. "gid://shopify/ProductVariant/1234567890"
     * @param  array<string, mixed>  $input  ProductVariantInput fields: price, sku, inventoryQuantity, etc.
     * @return array<string, mixed>
     */
    public function updateVariant(string $variantId, array $input): array
    {
        $gql = <<<'GQL'
        mutation($input: ProductVariantInput!) {
            productVariantUpdate(input: $input) {
                productVariant {
                    id
                    title
                    sku
                    price
                    inventoryQuantity
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
            'input' => array_merge(['id' => $variantId], $input),
        ]);
    }

    // ----------------------------------------------------------------
    // Collections
    // ----------------------------------------------------------------

    /**
     * List all collections (custom and smart).
     *
     * @param  int  $first  Number of collections to return
     * @param  string  $after  Cursor for pagination
     * @param  string  $query  Filter string, e.g. "title:Summer"
     * @return array<string, mixed>
     */
    public function listCollections(int $first = 10, string $after = '', string $query = ''): array
    {
        $gql = <<<'GQL'
        query($first: Int!, $after: String, $query: String) {
            collections(first: $first, after: $after, query: $query) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        id
                        title
                        handle
                        updatedAt
                        productsCount { count }
                        image { url altText }
                    }
                }
            }
        }
        GQL;

        return $this->graphql($gql, [
            'first' => $first,
            'after' => $after ?: null,
            'query' => $query ?: null,
        ]);
    }
}
