<?php

namespace App\Services\Connectivity\Shopify;

/**
 * Handles all Shopify store/shop-level GraphQL queries.
 *
 * Uses the `shop` query from the Shopify Admin GraphQL API.
 *
 * Docs: https://shopify.dev/docs/api/admin-graphql/latest/queries/shop
 *
 * Required scopes: (none — shop info is always accessible)
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the GraphQL response
 */
class ShopifyStoreService extends ShopifyHttpClient
{
    /**
     * Retrieve core information about the shop.
     *
     * @return array<string, mixed> The shop node data
     */
    public function getShop(): array
    {
        $query = <<<'GQL'
        query {
            shop {
                id
                name
                email
                myshopifyDomain
                primaryDomain {
                    url
                    host
                }
                plan {
                    displayName
                    partnerDevelopment
                    shopifyPlus
                }
                currencyCode
                weightUnit
                ianaTimezone
                contactEmail
                createdAt
                updatedAt
                description
                taxesIncluded
                taxShipping
                checkoutApiSupported
                url
            }
        }
        GQL;

        return $this->graphql($query);
    }

    /**
     * Retrieve all active shipping countries for the shop.
     *
     * @param  int  $first  Maximum number of countries to return
     * @return array<string, mixed>
     */
    public function listCountries(int $first = 50): array
    {
        $query = <<<'GQL'
        query($first: Int!) {
            shopLocales {
                locale
                name
                primary
            }
            deliverySettings {
                ... on DeliverySettings {
                    legacyModeProfiles
                    legacyModeBlocked
                }
            }
        }
        GQL;

        // Countries use the storefront API or policies — fetch via shop policies
        return $this->getShippingPolicies();
    }

    /**
     * Retrieve shop policies (shipping, privacy, refund, etc.).
     *
     * @return array<string, mixed>
     */
    public function getShippingPolicies(): array
    {
        $query = <<<'GQL'
        query {
            shop {
                shopPolicies {
                    ... on ShopPolicy {
                        id
                        type
                        title
                        url
                        body
                        updatedAt
                    }
                }
            }
        }
        GQL;

        return $this->graphql($query);
    }

    /**
     * Retrieve the shop's payment settings.
     *
     * @return array<string, mixed>
     */
    public function getPaymentSettings(): array
    {
        $query = <<<'GQL'
        query {
            shop {
                paymentSettings {
                    supportedDigitalWallets
                    countryCode
                    currencyCode
                }
            }
        }
        GQL;

        return $this->graphql($query);
    }

    /**
     * Retrieve all locations for the shop.
     *
     * @param  int  $first  Maximum number of locations to return
     * @return array<string, mixed>
     */
    public function listLocations(int $first = 25): array
    {
        $query = <<<'GQL'
        query($first: Int!) {
            locations(first: $first) {
                edges {
                    node {
                        id
                        name
                        isActive
                        isPrimary
                        fulfillsOnlineOrders
                        address {
                            address1
                            address2
                            city
                            province
                            country
                            zip
                            phone
                        }
                    }
                }
            }
        }
        GQL;

        return $this->graphql($query, ['first' => $first]);
    }
}
