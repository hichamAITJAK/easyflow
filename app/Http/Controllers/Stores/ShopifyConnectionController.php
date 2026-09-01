<?php

namespace App\Http\Controllers\Stores;

use App\DTOs\Shopify\ShopifyStoreDTO;
use App\Enums\EcomPlatform;
use App\Enums\StoreConnectionStatus;
use App\Events\Store\StoreConnected;
use App\Http\Controllers\Concerns\CreatesConnectedStore;
use App\Http\Controllers\Controller;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Connectivity\Shopify\ShopifyAuthService;
use App\Services\Connectivity\Shopify\ShopifyClient;
use App\Services\Operations\EcomPlatforms\ShopifyService;
use App\Services\PostHogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShopifyConnectionController extends Controller
{
    use CreatesConnectedStore;

    /**
     * Handle Shopify's OAuth callback: resolve the business + shop cached
     * for this connect token, verify the request, exchange the code for a
     * token, then create the store.
     */
    public function callback(Request $request, PostHogService $posthog): RedirectResponse
    {
        $connection = ShopifyService::resolveConnection((string) $request->query('state', ''));

        if (! $connection) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This connection request has expired. Please try again.')]);

            return to_route('stores.create');
        }

        $params = $request->query();
        $hmac = $params['hmac'] ?? '';
        unset($params['hmac']);

        if (! ShopifyAuthService::verifyHmac((string) config('services.shopify.client_secret'), $params, $hmac)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not verify this request came from Shopify.')]);

            return to_route('stores.create');
        }

        $shop = $connection['shop'];

        try {
            $tokenData = (new ShopifyService)->authentication((string) $request->query('code', ''), $shop);
        } catch (ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not reach Shopify — check your connection and try again.')]);

            return to_route('stores.create');
        } catch (RequestException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Shopify rejected the authorization — the code may have expired. Please try again.')]);

            return to_route('stores.create');
        }

        $client = new ShopifyClient($shop, $tokenData->accessToken);
        $shopInfo = ShopifyStoreDTO::fromArray($client->store()->getShop());

        $platform = EcommercePlatform::where('slug', EcomPlatform::SHOPIFY->value)->firstOrFail();

        $store = $this->createStoreOrRedirectBack([
            'business_id' => $connection['business_id'],
            'platform_id' => $platform->id,
            'name' => $shopInfo->name !== '' ? $shopInfo->name : $shop,
            'domain' => $shopInfo->domain,
            'logo_url' => $platform->logo_url,
            'meta' => [
                'email' => $shopInfo->email,
                'phone' => $shopInfo->phone,
                'currency' => $shopInfo->currency,
                'country' => $shopInfo->country,
                'plan' => $shopInfo->plan,
            ],
            'external_store_id' => $shop,
            'api_credentials' => json_encode($tokenData->toArray()),
            'connection_status' => StoreConnectionStatus::CONNECTED,
        ]);

        if ($store instanceof RedirectResponse) {
            return $store;
        }

        StoreConnected::dispatch($store);

        // PostHog: Track store connection
        $posthog->capture((string) $request->user()->id, 'store_connected', [
            'platform' => 'shopify',
            'store_name' => $store->name,
            'business_id' => $store->business_id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shopify store connected.')]);

        return to_route('stores.connected', $store);
    }
}
