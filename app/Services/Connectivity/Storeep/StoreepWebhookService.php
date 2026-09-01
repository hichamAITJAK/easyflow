<?php

namespace App\Services\Connectivity\Storeep;

/**
 * Storeep webhooks endpoint.
 *
 * Listing requires `webhooks:read`; create/update/delete require
 * `webhooks:write`. A store is capped at 20 webhooks total — creating
 * beyond that returns 422.
 *
 * Storeep addresses a webhook by `?id=` query parameter rather than a path
 * segment, for both update and delete.
 *
 * Docs: https://docs.storeep.com/webhooks
 */
class StoreepWebhookService extends StoreepHttpClient
{
    /**
     * Supported trigger events.
     */
    public const EVENT_SESSION_CREATED = 'session-created';

    public const EVENT_ORDER_CREATED = 'order-created';

    public const EVENT_ORDER_UPDATED = 'order-updated';

    /**
     * List this store's webhooks.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->get('/webhooks', $query);
    }

    /**
     * Create a webhook. `format` is always `json` — it's the only value
     * Storeep accepts.
     *
     * @return array{status?: string, data?: array{id?: int|string}}
     */
    public function subscribe(string $event, string $urlOrEmail, string $name = 'EasyFlow', string $type = 'api'): array
    {
        return $this->post('/webhooks', [
            'name' => $name,
            'type' => $type,
            'event' => $event,
            'format' => 'json',
            'url_or_email' => $urlOrEmail,
            'is_enabled' => true,
        ]);
    }

    /**
     * Delete a webhook by id.
     *
     * @return array<string, mixed>
     */
    public function unsubscribe(string $webhookId): array
    {
        return $this->http->delete('/webhooks?id='.urlencode($webhookId))->throw()->json();
    }
}
