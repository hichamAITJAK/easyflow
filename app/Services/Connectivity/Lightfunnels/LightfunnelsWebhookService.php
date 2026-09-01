<?php

namespace App\Services\Connectivity\Lightfunnels;

/**
 * Handles Lightfunnels webhook subscription management.
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the GraphQL response
 */
class LightfunnelsWebhookService extends LightfunnelsHttpClient
{
    /**
     * List all registered webhooks.
     *
     * @return array<string, mixed>
     */
    public function listWebhooks(): array
    {
        $gql = <<<'GQL'
        query WebhooksQuery {
            webhooks {
                id
                _id
                version
                url
                type
            }
        }
        GQL;

        return $this->graphql($gql);
    }

    /**
     * Subscribe to a webhook topic, delivered to the given callback URL.
     *
     * @param  string  $type  The event topic, e.g. "order/confirmed"
     * @param  array<string, mixed>  $settings  Extra webhook settings, e.g. ['segments_uids' => [...]]
     * @return array<string, mixed>
     */
    public function createWebhook(string $type, string $callbackUrl, array $settings = []): array
    {
        $gql = <<<'GQL'
        mutation webhooksCreateMutation($node: WebhookInput!) {
            createWebhook(node: $node) {
                id
                _id
                type
                url
            }
        }
        GQL;

        return $this->graphql($gql, [
            'node' => [
                'type' => $type,
                'url' => $callbackUrl,
                'settings' => $settings,
            ],
        ]);
    }

    /**
     * Delete a webhook by its id.
     *
     * @return array<string, mixed>
     */
    public function deleteWebhook(string $webhookId): array
    {
        $gql = <<<'GQL'
        mutation webhooksDeleteMutation($id: ID!) {
            deleteWebhook(id: $id)
        }
        GQL;

        return $this->graphql($gql, ['id' => $webhookId]);
    }
}
