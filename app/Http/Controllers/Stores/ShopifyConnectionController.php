<?php

namespace App\Http\Controllers\Stores;

use App\DTOs\Shopify\ShopifyStoreDTO;
use App\Enums\EcomPlatform;
use App\Enums\StoreConnectionStatus;
use App\Events\Store\StoreConnected;
use App\Http\Controllers\Concerns\CreatesConnectedStore;
use App\Http\Controllers\Controller;
use App\Models\EcommercePlatform;
use App\Models\ShopifyConnectionRequest;
use App\Services\Connectivity\Shopify\ShopifyAuthService;
use App\Services\Connectivity\Shopify\ShopifyClient;
use App\Services\Operations\EcomPlatforms\ShopifyService;
use App\Services\PostHogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connect a Shopify store with the merchant's own Shopify app.
 *
 * Shopify's custom-distribution apps are tied to one store, so there is
 * no platform-wide app: the merchant creates an app in their Shopify Dev
 * Dashboard, pastes its client id and secret here along with the shop
 * domain, and then finishes in Shopify — either through our redirect to
 * Shopify's authorize page, or through the app's own install link. Both
 * end on the same callback, which turns the saved keys plus the returned
 * code into a connected store.
 */
class ShopifyConnectionController extends Controller
{
    use CreatesConnectedStore;

    /**
     * Save the merchant's shop domain and app keys, then send them to the
     * finish page. Nothing is persisted on the store yet: Shopify has not
     * confirmed anything.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->business_id === null, 403);

        $validated = $request->validate([
            'shop' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:120'],
            'client_secret' => ['required', 'string', 'max:255'],
        ]);

        $shop = ShopifyService::normalizeShop($validated['shop']);

        if (! preg_match('/^[a-z0-9-]+$/i', $shop)) {
            return back()->withErrors([
                'shop' => __('That doesn\'t look like a valid Shopify store domain (e.g. "my-store" or "my-store.myshopify.com").'),
            ])->withInput();
        }

        $pending = ShopifyConnectionRequest::updateOrCreate(
            ['business_id' => $request->user()->business_id, 'shop' => $shop],
            [
                'client_id' => trim($validated['client_id']),
                'client_secret' => trim($validated['client_secret']),
                'state' => Str::random(40),
            ],
        );

        return to_route('stores.connect.shopify.finish', $pending);
    }

    /**
     * The "finish in Shopify" step: a button to Shopify's authorize page
     * for the saved shop, plus what the merchant's app must be configured
     * with, since a wrong redirect URL is the usual reason an install
     * link fails.
     */
    public function finish(Request $request, ShopifyConnectionRequest $pending): Response
    {
        abort_unless($pending->business_id === $request->user()->business_id, 403);

        return Inertia::render('stores/shopify-finish', [
            'pending' => [
                'id' => $pending->id,
                'shop' => $pending->shop,
                'domain' => "{$pending->shop}.myshopify.com",
                'client_id' => $pending->client_id,
            ],
            'authorizeUrl' => (new ShopifyService)->authorizationUrlFor($pending),
            'redirectUri' => route('stores.connect.shopify.callback', absolute: true),
            'scopes' => implode(',', config('services.shopify.scopes')),
        ]);
    }

    /**
     * Shopify's OAuth callback. Reached two ways: with our `state` after the
     * authorize redirect, or with only `shop` when the merchant installed
     * from the app's install link. Either way the pending request holds
     * the keys needed to verify the request and exchange the code.
     */
    public function callback(Request $request, PostHogService $posthog): RedirectResponse
    {
        $pending = $this->resolvePending($request);

        if ($pending === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No Shopify connection is waiting for this store. Enter your app keys first, then finish in Shopify.')]);

            return to_route('stores.create');
        }

        if ($pending->business_id !== $request->user()->business_id) {
            abort(403);
        }

        $params = $request->query();
        $hmac = $params['hmac'] ?? '';
        unset($params['hmac']);

        if (! ShopifyAuthService::verifyHmac($pending->client_secret, $params, $hmac)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not verify this request came from Shopify. Check the client secret you entered.')]);

            return to_route('stores.connect.shopify.finish', $pending);
        }

        $shop = $pending->shop;

        try {
            $tokenData = (new ShopifyService)->authentication(
                (string) $request->query('code', ''),
                $shop,
                $pending->client_id,
                $pending->client_secret,
            );
        } catch (ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not reach Shopify — check your connection and try again.')]);

            return to_route('stores.connect.shopify.finish', $pending);
        } catch (RequestException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Shopify rejected the authorization — check the client id and secret, then try again.')]);

            return to_route('stores.connect.shopify.finish', $pending);
        }

        $client = new ShopifyClient($shop, $tokenData->accessToken);
        $shopInfo = ShopifyStoreDTO::fromArray($client->store()->getShop());

        $platform = EcommercePlatform::where('slug', EcomPlatform::SHOPIFY->value)->firstOrFail();

        $store = $this->createStoreOrRedirectBack([
            'business_id' => $pending->business_id,
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
            // The merchant's own app keys travel with the token: webhooks
            // and token refreshes for this store are verified and signed
            // with them, never with a platform-wide secret.
            'api_credentials' => json_encode([
                ...$tokenData->toArray(),
                'client_id' => $pending->client_id,
                'client_secret' => $pending->client_secret,
            ]),
            'connection_status' => StoreConnectionStatus::CONNECTED,
        ]);

        if ($store instanceof RedirectResponse) {
            return $store;
        }

        $pending->delete();

        StoreConnected::dispatch($store);

        $posthog->capture((string) $request->user()->id, 'store_connected', [
            'platform' => 'shopify',
            'store_name' => $store->name,
            'business_id' => $store->business_id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shopify store connected.')]);

        return to_route('stores.connected', $store);
    }

    /**
     * The pending request this callback belongs to: by our `state` when we
     * started the redirect, else by the `shop` Shopify always sends — the
     * install-link path never carries a state of ours.
     */
    private function resolvePending(Request $request): ?ShopifyConnectionRequest
    {
        $state = (string) $request->query('state', '');

        if ($state !== '') {
            $byState = ShopifyConnectionRequest::where('state', $state)->first();

            if ($byState !== null) {
                return $byState;
            }
        }

        $shop = ShopifyService::normalizeShop((string) $request->query('shop', ''));

        if ($shop === '') {
            return null;
        }

        return ShopifyConnectionRequest::where('shop', $shop)
            ->where('business_id', $request->user()->business_id)
            ->latest('id')
            ->first();
    }
}
