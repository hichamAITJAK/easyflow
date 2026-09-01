<?php

namespace App\Http\Controllers\Webhooks;

use App\Events\Webhook\WebhookSignatureVerificationFailed;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessStoreepOrderWebhookJob;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StoreepWebhookController extends Controller
{
    /**
     * Receive a Storeep order webhook and queue it for order creation.
     *
     * Storeep documents no signing secret and no signature header, so
     * there is no HMAC to verify the way there is for Shopify and YouCan.
     * Authentication is instead a per-store random secret embedded in the
     * URL Storeep was told to call (see
     * StoreepService::registerOrderWebhook), compared here in constant
     * time.
     *
     * That alone only proves the caller knows the URL, so the queued job
     * does not trust the delivered body either — it re-fetches the order
     * from Storeep's API by id. A leaked URL therefore buys an attacker a
     * wasted API call, not a forged order.
     *
     * Docs: https://docs.storeep.com/webhooks
     */
    public function handle(Request $request, Store $store, string $secret): Response
    {
        $expected = (string) $store->webhook_secret;

        if ($expected === '' || ! hash_equals($expected, $secret)) {
            WebhookSignatureVerificationFailed::dispatch($store, 'storeep');

            abort(401);
        }

        ProcessStoreepOrderWebhookJob::dispatch($store, $request->json()->all());

        return response()->noContent();
    }
}
