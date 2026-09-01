<?php

namespace App\Services\Connectivity\WooCommerce;

/**
 * WooCommerce webhook endpoints.
 *
 * A webhook's `topic` is `resource.event` — the core resources are coupon,
 * customer, order and product. Deliveries are HTTP POSTs carrying, among
 * others, an `X-WC-Webhook-Signature` header: the base64-encoded
 * HMAC-SHA256 of the raw request body, keyed with the webhook's `secret`.
 *
 * Note WooCommerce disables a webhook after 5 consecutive non-2xx
 * responses, and re-enabling it requires an API call — so the receiving
 * controller must answer 2xx promptly and do its real work on the queue.
 *
 * Docs: https://woocommerce.github.io/woocommerce-rest-api-docs/#webhooks
 */
class WooCommerceWebhookService extends WooCommerceHttpClient
{
    public const TOPIC_ORDER_CREATED = 'order.created';

    public const TOPIC_ORDER_UPDATED = 'order.updated';

    /**
     * Register a webhook.
     *
     * `secret` is always passed explicitly: when it is omitted WooCommerce
     * silently falls back to signing with the consumer key, which would make
     * verification depend on which API key happened to create the webhook.
     *
     * @return array<string, mixed>
     */
    public function subscribe(string $topic, string $deliveryUrl, string $secret, string $name): array
    {
        return $this->post('/webhooks', [
            'name' => $name,
            'topic' => $topic,
            'delivery_url' => $deliveryUrl,
            'secret' => $secret,
            'status' => 'active',
        ]);
    }

    /**
     * List this store's webhooks.
     *
     * @param  array<string, mixed>  $query
     * @return array{items: array<int, array<string, mixed>>, totalPages: int}
     */
    public function list(array $query = []): array
    {
        return $this->getPage('/webhooks', $query);
    }

    /**
     * Delete a webhook.
     *
     * `force=true` is required — webhooks do not support trashing, and
     * without it WooCommerce rejects the request.
     *
     * @return array<string, mixed>
     */
    public function unsubscribe(int|string $webhookId): array
    {
        return $this->delete("/webhooks/{$webhookId}", ['force' => true]);
    }
}
