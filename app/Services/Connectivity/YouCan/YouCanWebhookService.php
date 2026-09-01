<?php

namespace App\Services\Connectivity\YouCan;

/**
 * Handles YouCan REST Hook (webhook) subscription management.
 *
 * Docs: https://developer.youcan.shop/store-admin/resthooks/subscribe
 *
 * A store may have at most 3 subscriptions total, 1 per event. Requires
 * the `edit-rest-hooks` OAuth scope.
 */
class YouCanWebhookService extends YouCanHttpClient
{
    /**
     * Subscribe to a REST Hook event, delivered as a signed POST to the
     * given callback URL.
     *
     * @param  string  $event  e.g. "order.create", "inventory.low", "upsell.accept"
     * @return array<string, mixed> the subscription payload, e.g. {"id": "..."}
     */
    public function subscribe(string $event, string $targetUrl): array
    {
        return $this->post('/resthooks/subscribe', [
            'event' => $event,
            'target_url' => $targetUrl,
        ]);
    }

    /**
     * List the store's active REST Hook subscriptions.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->get('/resthooks/list');
    }

    /**
     * Unsubscribe from a REST Hook by its subscription id.
     *
     * @return array<string, mixed>
     */
    public function unsubscribe(string $subscriptionId): array
    {
        return $this->post("/resthooks/unsubscribe/{$subscriptionId}");
    }
}
