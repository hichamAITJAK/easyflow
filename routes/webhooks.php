<?php

use App\Http\Controllers\Webhooks\ShopifyComplianceWebhookController;
use App\Http\Controllers\Webhooks\ShopifyWebhookController;
use App\Http\Controllers\Webhooks\StoreepWebhookController;
use App\Http\Controllers\Webhooks\WooCommerceWebhookController;
use App\Http\Controllers\Webhooks\YouCanWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Order-ingestion webhooks are throttled per store (PRD: rate-limited "per
 * tenant/per store, not just per IP"). Every delivery from one platform
 * arrives from that platform's own egress addresses, so a per-IP limit
 * would let one busy store throttle every other store behind those same
 * addresses.
 *
 * A 429 here is not a dropped order: all four platforms retry on non-2xx.
 * The limit is set high enough that only a runaway loop or abuse reaches
 * it — see AppServiceProvider::configureWebhookRateLimiting().
 */
Route::middleware('throttle:webhook-store')->group(function (): void {
    Route::post('/webhooks/shopify/{store}', [ShopifyWebhookController::class, 'handle'])->name('webhooks.shopify.receive');
    Route::post('/webhooks/youcan/{store}/create-order', [YouCanWebhookController::class, 'handle'])->name('webhooks.youcan.create-order');

    /*
     * Storeep publishes no webhook signing secret and no signature header, so
     * unlike the routes above there is no HMAC to verify the delivery with. The
     * {secret} segment is a per-store random string generated at subscription
     * time and stored on stores.webhook_secret — it makes the endpoint
     * unguessable and is what the controller checks. The queued job then
     * re-fetches the order from Storeep's API rather than trusting the body, so
     * knowing this URL is not enough to inject an order.
     */
    Route::post('/webhooks/storeep/{store}/create-order/{secret}', [StoreepWebhookController::class, 'handle'])->name('webhooks.storeep.create-order');

    /*
     * WooCommerce signs every delivery with an HMAC-SHA256 of the raw body,
     * base64-encoded in X-WC-Webhook-Signature. Unlike Shopify and YouCan, the
     * signing key is not one app-wide client secret — it is a per-store secret
     * generated at subscription time and held on stores.webhook_secret, because
     * WooCommerce is self-hosted and there is no shared app to key against.
     */
    Route::post('/webhooks/woocommerce/{store}/create-order', [WooCommerceWebhookController::class, 'handle'])->name('webhooks.woocommerce.create-order');
});

/*
 * Shopify's mandatory compliance webhooks, plus app/uninstalled.
 *
 * These are shop-scoped, not store-scoped: Shopify sends them with no store
 * id in the URL, so the controller resolves the shop from the
 * X-Shopify-Shop-Domain header. shop/redact in particular arrives 48 hours
 * after uninstall, when the Store row may already be gone — hence no route
 * model binding here.
 *
 * The paths must match what is configured in the Partner Dashboard (or the
 * [webhooks.privacy_compliance] block of shopify.app.toml).
 */
Route::prefix('webhooks/shopify')->name('webhooks.shopify.')->middleware('throttle:webhook-compliance')->group(function (): void {
    Route::post('/customers/data-request', [ShopifyComplianceWebhookController::class, 'customersDataRequest'])->name('customers.data-request');
    Route::post('/customers/redact', [ShopifyComplianceWebhookController::class, 'customersRedact'])->name('customers.redact');
    Route::post('/shop/redact', [ShopifyComplianceWebhookController::class, 'shopRedact'])->name('shop.redact');
    Route::post('/app/uninstalled', [ShopifyComplianceWebhookController::class, 'appUninstalled'])->name('app.uninstalled');
});
