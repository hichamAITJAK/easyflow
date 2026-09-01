<?php

namespace App\Events\Webhook;

use App\Models\Store;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an inbound store webhook's signature fails verification,
 * before the request is aborted. Carries no raw payload — per section 4's
 * security requirement (no plaintext PII in logs/error reports), a
 * listener must only log metadata (store, provider, timestamp), never the
 * request body.
 *
 * The store is nullable because Shopify's shop-scoped compliance webhooks
 * (customers/redact, shop/redact) carry no store id and may arrive after the
 * Store row has already been deleted — an unverifiable one still needs to be
 * recorded.
 */
class WebhookSignatureVerificationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ?Store $store,
        public readonly string $provider,
    ) {}
}
