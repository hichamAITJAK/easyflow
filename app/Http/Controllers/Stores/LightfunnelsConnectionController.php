<?php

namespace App\Http\Controllers\Stores;

use App\DTOs\Lightfunnels\LightfunnelsStoreDTO;
use App\Enums\EcomPlatform;
use App\Enums\StoreConnectionStatus;
use App\Events\Store\StoreConnected;
use App\Http\Controllers\Concerns\CreatesConnectedStore;
use App\Http\Controllers\Controller;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Connectivity\Lightfunnels\LightfunnelsClient;
use App\Services\Operations\EcomPlatforms\LightfunnelsService;
use App\Services\PostHogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class LightfunnelsConnectionController extends Controller
{
    use CreatesConnectedStore;

    /**
     * Handle Lightfunnels' OAuth callback: resolve the business cached for
     * this connect token, exchange the code for a token, fetch the account's
     * store details, create the store, then run its initial product/order sync.
     */
    public function callback(Request $request, PostHogService $posthog): RedirectResponse
    {
        $businessId = LightfunnelsService::resolveBusinessId((string) $request->query('state', ''));

        if (! $businessId) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This connection request has expired. Please try again.')]);

            return to_route('stores.create');
        }

        try {
            $tokenData = (new LightfunnelsService)->authentication((string) $request->query('code', ''));
        } catch (ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not reach Lightfunnels — check your connection and try again.')]);

            return to_route('stores.create');
        } catch (RequestException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Lightfunnels rejected the authorization — the code may have expired. Please try again.')]);

            return to_route('stores.create');
        }

        $client = new LightfunnelsClient($tokenData->accessToken);

        // Lightfunnels' OAuth grants access to the whole account, which may
        // hold several stores — there's no store picker in the connect flow,
        // so this connection represents the account's first (primary) store.
        // Any other stores on the same account can be filtered to correctly
        // by LightfunnelsService once connected (see its loadProducts()).
        try {
            $storeInfo = $this->resolvePrimaryStore($client);
        } catch (\Throwable $e) {
            Log::warning('Lightfunnels store details fetch failed', ['error' => $e->getMessage()]);
            $storeInfo = null;
        }

        $platform = EcommercePlatform::where('slug', EcomPlatform::LIGHTFUNNELS->value)->firstOrFail();

        $store = $this->createStoreOrRedirectBack([
            'business_id' => $businessId,
            'platform_id' => $platform->id,
            'name' => $storeInfo && $storeInfo->name !== '' ? $storeInfo->name : 'Lightfunnels store',
            'slug' => $storeInfo?->slug,
            'domain' => $storeInfo?->domain,
            'logo_url' => $platform->logo_url,
            'meta' => $storeInfo ? [
                'email' => $storeInfo->email,
                'currency' => $storeInfo->currency,
                'address' => $storeInfo->address,
                'legal_name' => $storeInfo->legalName,
            ] : null,
            'external_store_id' => $storeInfo && $storeInfo->id !== '' ? $storeInfo->id : 'N/A',
            'api_credentials' => json_encode($tokenData->toArray()),
            'connection_status' => StoreConnectionStatus::CONNECTED,
        ]);

        if ($store instanceof RedirectResponse) {
            return $store;
        }

        StoreConnected::dispatch($store);

        // PostHog: Track store connection
        $posthog->capture((string) $request->user()->id, 'store_connected', [
            'platform' => 'lightfunnels',
            'store_name' => $store->name,
            'business_id' => $store->business_id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lightfunnels store connected.')]);

        return to_route('stores.connected', $store);
    }

    /**
     * Resolve the account's primary store from the OAuth-granted token.
     * Lightfunnels' listStores() returns every store on the account in a
     * single account-scoped query — the first one is treated as this
     * connection's store, since the OAuth flow has no store picker.
     */
    private function resolvePrimaryStore(LightfunnelsClient $client): ?LightfunnelsStoreDTO
    {
        $stores = $client->store()->listStores()['account']['stores'] ?? [];

        if ($stores === []) {
            return null;
        }

        return LightfunnelsStoreDTO::fromArray($stores[0]);
    }
}
