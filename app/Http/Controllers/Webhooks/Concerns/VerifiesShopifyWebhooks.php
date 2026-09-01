<?php

namespace App\Http\Controllers\Webhooks\Concerns;

use App\Models\Store;
use Illuminate\Http\Request;

/**
 * Shared HMAC verification for Shopify webhook endpoints.
 *
 * Shopify's webhook HMAC is a different scheme from the OAuth callback HMAC
 * verified by ShopifyAuthService::verifyHmac() (which validates a sorted,
 * url-encoded query string) — this one is computed over the raw request body
 * and delivered base64-encoded in the X-Shopify-Hmac-Sha256 header.
 */
trait VerifiesShopifyWebhooks
{
    /**
     * Constant-time check that this request body was signed by Shopify.
     */
    protected function hasValidShopifySignature(Request $request): bool
    {
        $computed = base64_encode(hash_hmac(
            'sha256',
            $request->getContent(),
            (string) config('services.shopify.client_secret'),
            true,
        ));

        return hash_equals($computed, (string) $request->header('X-Shopify-Hmac-Sha256', ''));
    }

    /**
     * Resolve the store this webhook belongs to via the X-Shopify-Shop-Domain
     * header, which Shopify sends on every delivery.
     *
     * Mandatory compliance webhooks are shop-scoped rather than store-scoped —
     * they carry no store id in the URL, and `shop/redact` arrives 48 hours
     * after uninstall, by which point the Store row may already be gone. So a
     * null return here is an expected outcome, not an error.
     */
    protected function resolveStoreFromShopDomain(Request $request): ?Store
    {
        $shopDomain = (string) $request->header('X-Shopify-Shop-Domain', '');

        if ($shopDomain === '') {
            return null;
        }

        // Match both storage shapes: the OAuth callback normalises the shop to
        // its slug, but stores connected by other paths keep the full domain.
        $slug = str_replace('.myshopify.com', '', $shopDomain);

        return Store::withoutGlobalScopes()
            ->whereIn('external_store_id', [$slug, "{$slug}.myshopify.com"])
            ->first();
    }
}
