<?php

namespace App\Services\Connectivity\Lightfunnels;

/**
 * Handles all Lightfunnels store-level GraphQL queries and mutations.
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the GraphQL response
 */
class LightfunnelsStoreService extends LightfunnelsHttpClient
{
    /**
     * Retrieve all stores on the account.
     *
     * @return array<string, mixed>
     */
    public function listStores(): array
    {
        $gql = <<<'GQL'
        query AccountQuery {
            account {
                stores {
                    id
                    uid
                    name
                    slug
                    currency
                    defaultDomain
                    address
                    legal_name
                    email
                }
            }
        }
        GQL;

        return $this->graphql($gql);
    }

    /**
     * Create a new store.
     *
     * @param  array<string, mixed>  $node  StoreInput fields (e.g. name, currency, etc.)
     * @return array<string, mixed>
     */
    public function createStore(array $node): array
    {
        $gql = <<<'GQL'
        mutation createStore($node: StoreInput!) {
            createStore(node: $node) {
                store {
                    id
                    uid
                    name
                    slug
                    currency
                    defaultDomain
                    address
                    legal_name
                    email
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['node' => $node]);
    }

    /**
     * Retrieve a specific store by its ID.
     *
     * @return array<string, mixed>
     */
    public function getStore(string $id): array
    {
        $gql = <<<'GQL'
        query StoreQuery($id: ID!) {
            node(id: $id) {
                ... on Store {
                    id
                    uid
                    name
                    slug
                    currency
                    defaultDomain
                    address
                    legal_name
                    email
                }
            }
        }
        GQL;

        return $this->graphql($gql, ['id' => $id]);
    }

    /**
     * Retrieve the authenticated account's tracking pixels and integrations.
     *
     * @return array<string, mixed>
     */
    public function getAccount(): array
    {
        $gql = <<<'GQL'
        query AccountQuery {
            account {
                id
                facebook_pixels {
                    label
                    value
                }
                snapchat_pixels {
                    label
                    value
                }
                tiktok_pixels {
                    label
                    value
                }
                integrations {
                    id
                    label
                    platform
                }
            }
        }
        GQL;

        return $this->graphql($gql);
    }

    /**
     * Update a store's properties.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function updateStore(string $id, array $node): array
    {
        $gql = <<<'GQL'
        mutation updateStoreMutation($node: StoreUpdateInput!, $id: ID!) {
            updateStore(node: $node, id: $id) {
                id
                uid
                name
                slug
                currency
                defaultDomain
                address
                legal_name
                email
            }
        }
        GQL;

        return $this->graphql($gql, ['node' => $node, 'id' => $id]);
    }

    /**
     * Delete stores.
     *
     * @param  array<int, string>  $items
     * @return array<string, mixed>
     */
    public function deleteStore(array $items): array
    {
        $gql = <<<'GQL'
        mutation deleteStoresMutation($items: [ID!]!) {
            deleteStore(items: $items) {
                id
            }
        }
        GQL;

        return $this->graphql($gql, ['items' => $items]);
    }

    /**
     * Add existing products to a store.
     *
     * @param  array<int, string>  $productsUids
     * @return array<string, mixed>
     */
    public function addProductsToStore(string $id, array $productsUids): array
    {
        $gql = <<<'GQL'
        mutation addProductsToStore($node: AddProductsToStoreInput!, $id: ID!) {
            addProductsToStore(id: $id, node: $node) {
                id
                products(cursor: null) {
                    title
                    price
                    link
                }
            }
        }
        GQL;

        return $this->graphql($gql, [
            'id' => $id,
            'node' => ['products_uids' => $productsUids],
        ]);
    }
}
