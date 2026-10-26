<?php

namespace App\Http\Controllers\Stores;

use App\Enums\EcomPlatform;
use App\Http\Controllers\Controller;
use App\Models\EcommercePlatform;
use App\Services\Operations\EcomPlatforms\LightfunnelsService;
use App\Services\Operations\EcomPlatforms\YouCanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
            EcomPlatform::LIGHTFUNNELS => (new LightfunnelsService)->connect($request->user()->business_id),
            default => abort(404, __('That platform isn\'t connectable yet.')),
        };

        return redirect()->away($url);
    }
}
