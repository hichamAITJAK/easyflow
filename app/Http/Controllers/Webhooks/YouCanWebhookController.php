<?php

namespace App\Http\Controllers\Webhooks;

use App\Events\Webhook\WebhookSignatureVerificationFailed;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessYouCanOrderWebhookJob;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class YouCanWebhookController extends Controller
{
    /**
     * Receive a YouCan REST Hook delivery and queue it for order creation.
     *
     * YouCan signs the JSON-encoded payload with HMAC-SHA256, keyed by the
     * OAuth client secret, and sends it hex-encoded in the
     * X-Youcan-Signature header.
     *
     * Docs: https://developer.youcan.shop/store-admin/resthooks
     */
    public function handle(Request $request, Store $store): Response
    {
        $computed = hash_hmac(
            'sha256',
            $request->getContent(),
            (string) config('services.youcan.client_secret'),
        );

        if (! hash_equals($computed, $request->header('X-Youcan-Signature', ''))) {
            WebhookSignatureVerificationFailed::dispatch($store, 'youcan');

            abort(401);
        }

        ProcessYouCanOrderWebhookJob::dispatch($store, $request->json()->all());

        return response()->noContent();
    }
}
