<?php

namespace App\Services\Connectivity\Shopify;

/**
 * Handles Shopify webhook subscription management via the Admin GraphQL API.
 *
 * Docs: https://shopify.dev/docs/api/admin-graphql/latest/mutations/webhooksubscriptioncreate
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the GraphQL response
 */
class ShopifyWebhookService extends ShopifyHttpClient
{
    /**
     * Subscribe to a webhook topic, delivered as JSON to the given callback URL.
     *
     * @return array<string, mixed> The webhookSubscriptionCreate payload
     */
    public function subscribe(string $topic, string $callbackUrl): array
    {
        $query = <<<'GQL'
        mutation($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
            webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
                webhookSubscription {
                    id
                    topic
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        $result = $this->graphql($query, [
            'topic' => $topic,
            'webhookSubscription' => [
                'callbackUrl' => $callbackUrl,
                'format' => 'JSON',
            ],
        ]);

        /** @var array<int, array<string, mixed>> $errors */
        $errors = $result['webhookSubscriptionCreate']['userErrors'] ?? [];

        if ($errors !== []) {
            $message = collect($errors)->pluck('message')->implode('; ');

            throw new \RuntimeException("Shopify webhook subscription failed: {$message}");
        }

        return $result;
    }
}
