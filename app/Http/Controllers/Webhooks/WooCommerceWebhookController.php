<?php

namespace App\Http\Controllers\Webhooks;

use App\Events\Webhook\WebhookSignatureVerificationFailed;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWooCommerceOrderWebhookJob;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WooCommerceWebhookController extends Controller
{
    /**
     * Receive a WooCommerce order webhook and queue it for order creation.
     *
     * WooCommerce signs the raw request body with HMAC-SHA256, keyed by the
     * webhook's own `secret`, and sends it base64-encoded in the
     * X-WC-Webhook-Signature header. That secret is per-store, generated at
     * subscription time and stored on stores.webhook_secret — unlike
     * Shopify and YouCan, whose signing key is one app-wide client secret.
     *
     * The signature is computed over the RAW body. Re-encoding
     * $request->json() first would change the bytes (key order, unicode
     * escaping, float formatting) and never match.
     *
     * WooCommerce disables a webhook after 5 consecutive non-2xx responses,
     * and re-enabling it needs an API call — so this returns promptly and
     * leaves the real work to the queue.
     *
     * Docs: https://woocommerce.github.io/woocommerce-rest-api-docs/#webhooks
     */
    public function handle(Request $request, Store $store): Response
    {
        $secret = (string) $store->webhook_secret;

        $computed = base64_encode(hash_hmac(
            'sha256',
            $request->getContent(),
            $secret,
            true,
        ));

        $provided = (string) $request->header('X-WC-Webhook-Signature', '');

        if ($secret === '' || ! hash_equals($computed, $provided)) {
            WebhookSignatureVerificationFailed::dispatch($store, 'woocommerce');

            abort(401);
        }

        // WooCommerce pings a newly created webhook with a body that carries
        // only the webhook's own id, to confirm the endpoint is reachable.
        // It is signed like any other delivery, so it arrives here — but it
        // is not an order and must not be queued as one.
        $payload = $request->json()->all();

        if (! isset($payload['id']) || isset($payload['webhook_id'])) {
            return response()->noContent();
        }

        ProcessWooCommerceOrderWebhookJob::dispatch($store, $payload);

        return response()->noContent();
    }
}
