<?php

namespace App\Http\Controllers\Webhooks;

use App\Events\Webhook\WebhookSignatureVerificationFailed;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopifyOrderWebhookJob;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopifyWebhookController extends Controller
{
    /**
     * Receive a Shopify webhook and queue it for order creation.
     *
     * Shopify's webhook HMAC is a different scheme from the OAuth callback HMAC
     * verified by ShopifyAuthService::verifyHmac() (which validates a sorted,
     * url-encoded query string) — this one is computed over the raw request
     * body and delivered base64-encoded in the X-Shopify-Hmac-Sha256 header.
     */
    public function handle(Request $request, Store $store): JsonResponse
    {
        $computed = base64_encode(hash_hmac(
            'sha256',
            $request->getContent(),
            (string) config('services.shopify.client_secret'),
            true,
        ));

        if (! hash_equals($computed, $request->header('X-Shopify-Hmac-Sha256', ''))) {
            WebhookSignatureVerificationFailed::dispatch($store, 'shopify');

            // Built explicitly rather than via abort(), so the body stays a
            // small JSON payload instead of a rendered error page carrying a
            // stack trace whenever APP_DEBUG is on.
            return response()->json(['error' => 'Invalid webhook signature.'], 401);
        }

        ProcessShopifyOrderWebhookJob::dispatch($store, $request->json()->all());

        return response()->json(['success' => true]);
    }
}
