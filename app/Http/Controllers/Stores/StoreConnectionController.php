<?php

namespace App\Http\Controllers\Stores;

use App\Enums\EcomPlatform;
use App\Http\Controllers\Controller;
use App\Models\EcommercePlatform;
use App\Services\Operations\EcomPlatforms\LightfunnelsService;
use App\Services\Operations\EcomPlatforms\ShopifyService;
use App\Services\Operations\EcomPlatforms\YouCanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StoreConnectionController extends Controller
{
    /**
     * Kick off the OAuth flow for the given platform: generate the
     * authorization URL and redirect the merchant to it. No store is
     * created here — it's created once the platform's callback confirms
     * the connection (see YouCanConnectionController / ShopifyConnectionController).
     */
    public function redirect(Request $request, string $platform): RedirectResponse
    {
        abort_if($request->user()->business_id === null, 403);

        $ecomPlatform = EcomPlatform::tryFrom($platform);
        abort_unless($ecomPlatform !== null, 404);

        abort_unless(
            EcommercePlatform::where('slug', $ecomPlatform->value)->exists(),
            404
        );

        $url = match ($ecomPlatform) {
            EcomPlatform::YOUCAN => (new YouCanService)->connect($request->user()->business_id),
            EcomPlatform::SHOPIFY => (new ShopifyService)->connect(
                $request->user()->business_id,
                $this->resolveShopifyShop($request),
            ),
            EcomPlatform::LIGHTFUNNELS => (new LightfunnelsService)->connect($request->user()->business_id),
            default => abort(404, __('That platform isn\'t connectable yet.')),
        };

        return redirect()->away($url);
    }

    /**
     * Normalize the pasted Shopify store domain/URL before validating it,
     * so a merchant pasting the full URL (with protocol or a trailing path)
     * doesn't fail validation on a strict bare-slug regex.
     */
    private function resolveShopifyShop(Request $request): string
    {
        $shop = ShopifyService::normalizeShop((string) $request->input('shop', ''));

        Validator::make(['shop' => $shop], [
            'shop' => ['required', 'string', 'regex:/^[a-z0-9-]+$/i'],
        ], [
            'shop.regex' => __('That doesn\'t look like a valid Shopify store domain (e.g. "my-store" or "my-store.myshopify.com").'),
        ])->validate();

        return $shop;
    }
}
