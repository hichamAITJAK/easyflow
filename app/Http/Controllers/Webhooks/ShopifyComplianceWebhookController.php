<?php

namespace App\Http\Controllers\Webhooks;

use App\Events\Webhook\WebhookSignatureVerificationFailed;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\VerifiesShopifyWebhooks;
use App\Jobs\Shopify\HandleCustomerDataRequestJob;
use App\Jobs\Shopify\HandleCustomerRedactJob;
use App\Jobs\Shopify\HandleShopAppUninstalledJob;
use App\Jobs\Shopify\HandleShopRedactJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shopify's mandatory compliance webhooks (GDPR/CCPA) plus app/uninstalled.
 *
 * Every public Shopify app must implement these three compliance topics —
 * Shopify verifies them at submission and delivers them regardless of the
 * app's declared scopes:
 *
 *   customers/data_request — merchant requests a copy of a customer's data
 *   customers/redact       — merchant requests erasure of a customer's data
 *   shop/redact            — fires 48h after uninstall; erase the whole shop
 *
 * Unlike ShopifyWebhookController::handle(), these are shop-scoped: they carry
 * no {store} route parameter, so the store is resolved from the
 * X-Shopify-Shop-Domain header (and may legitimately no longer exist).
 *
 * All four endpoints must answer within 5 seconds, so each one verifies the
 * signature synchronously and defers the actual work to a queued job.
 *
 * Docs: https://shopify.dev/docs/apps/build/privacy-law-compliance
 */
class ShopifyComplianceWebhookController extends Controller
{
    use VerifiesShopifyWebhooks;

    /**
     * customers/data_request — a merchant has asked, on a customer's behalf,
     * for the data this app holds about that customer.
     *
     * Shopify requires the data be delivered to the merchant within 30 days;
     * it does not collect the payload itself, so this queues an export rather
     * than returning anything in the response body.
     */
    public function customersDataRequest(Request $request): JsonResponse
    {
        return $this->accept($request, function (Request $request): void {
            HandleCustomerDataRequestJob::dispatch(
                $this->resolveStoreFromShopDomain($request),
                $request->json()->all(),
            );
        });
    }

    /**
     * customers/redact — erase this app's stored personal data for one
     * customer. Shopify sends this 10 days after a merchant requests erasure
     * (or 6 months after the customer's last order, if any).
     */
    public function customersRedact(Request $request): JsonResponse
    {
        return $this->accept($request, function (Request $request): void {
            HandleCustomerRedactJob::dispatch(
                $this->resolveStoreFromShopDomain($request),
                $request->json()->all(),
            );
        });
    }

    /**
     * shop/redact — fires 48 hours after a shop uninstalls the app. Erase all
     * personal data held for that shop.
     */
    public function shopRedact(Request $request): JsonResponse
    {
        return $this->accept($request, function (Request $request): void {
            HandleShopRedactJob::dispatch(
                $this->resolveStoreFromShopDomain($request),
                $request->json()->all(),
            );
        });
    }

    /**
     * app/uninstalled — not a compliance topic, but required in practice:
     * without it the app keeps an access token it can no longer use, and the
     * merchant's store stays visibly "connected" in the UI.
     */
    public function appUninstalled(Request $request): JsonResponse
    {
        return $this->accept($request, function (Request $request): void {
            HandleShopAppUninstalledJob::dispatch(
                $this->resolveStoreFromShopDomain($request),
            );
        });
    }

    /**
     * Verify the signature, then hand off to the given queueing callback.
     *
     * Returns a bare JSON 401 on an invalid signature rather than abort()ing:
     * Shopify's automated checks POST an unsigned request to each compliance
     * endpoint and expect a small, machine-readable response. Letting the
     * exception handler render it would leak a stack trace whenever
     * APP_DEBUG is on, so the response is built explicitly here.
     */
    private function accept(Request $request, callable $queue): JsonResponse
    {
        if (! $this->hasValidShopifySignature($request)) {
            WebhookSignatureVerificationFailed::dispatch(
                $this->resolveStoreFromShopDomain($request),
                'shopify',
            );

            return response()->json(['error' => 'Invalid webhook signature.'], 401);
        }

        $queue($request);

        return response()->json(['success' => true]);
    }
}
