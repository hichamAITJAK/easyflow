<?php

namespace App\Http\Controllers\Stores;

use App\DTOs\YouCan\YouCanStoreDTO;
use App\Enums\EcomPlatform;
use App\Enums\StoreConnectionStatus;
use App\Events\Store\StoreConnected;
use App\Http\Controllers\Concerns\CreatesConnectedStore;
use App\Http\Controllers\Controller;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Connectivity\YouCan\YouCanClient;
use App\Services\Operations\EcomPlatforms\YouCanService;
use App\Services\PostHogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class YouCanConnectionController extends Controller
{
    use CreatesConnectedStore;

    /**
     * Handle YouCan's OAuth callback: resolve the business stashed in the
     * session for this pending connection, exchange the code for a token,
     * then create the store.
     */
    public function callback(Request $request, PostHogService $posthog): RedirectResponse
    {
        $businessId = YouCanService::resolveBusinessId();

        if (! $businessId) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This connection request has expired. Please try again.')]);

            return to_route('stores.create');
        }

        try {
            $tokenData = (new YouCanService)->authentication((string) $request->query('code', ''));
        } catch (ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not reach YouCan — check your connection and try again.')]);

            return to_route('stores.create');
        } catch (RequestException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('YouCan rejected the authorization. Please try again.')]);

            return to_route('stores.create');
        }

        $client = new YouCanClient($tokenData->accessToken);

        try {
            $storeInfo = YouCanStoreDTO::fromArray($client->store()->getDetails());
        } catch (\Throwable $e) {
            Log::warning('YouCan store details fetch failed', ['error' => $e->getMessage()]);
            $storeInfo = null;
        }

        $platform = EcommercePlatform::where('slug', EcomPlatform::YOUCAN->value)->firstOrFail();

        $store = $this->createStoreOrRedirectBack([
            'business_id' => $businessId,
            'platform_id' => $platform->id,
            'external_store_id' => $storeInfo && $storeInfo->id !== '' ? $storeInfo->id : 'N/A',
            'name' => $storeInfo && $storeInfo->name !== '' ? $storeInfo->name : 'YouCan store',
            'slug' => $storeInfo?->slug,
            'domain' => $storeInfo?->domain,
            'logo_url' => $storeInfo && $storeInfo->logo !== null ? $storeInfo->logo : $platform->logo_url,
            'meta' => $storeInfo ? [
                'email' => $storeInfo->email,
                'phone' => $storeInfo->phone,
                'currency_code' => $storeInfo->currencyCode,
                'currency_symbol' => $storeInfo->currencySymbol,
                'status' => $storeInfo->status,
            ] : null,
            'api_credentials' => json_encode($tokenData->toArray()),
            'connection_status' => StoreConnectionStatus::CONNECTED,
        ]);

        if ($store instanceof RedirectResponse) {
            return $store;
        }

        StoreConnected::dispatch($store);

        // PostHog: Track store connection
        $posthog->capture((string) $request->user()->id, 'store_connected', [
            'platform' => 'youcan',
            'store_name' => $store->name,
            'business_id' => $store->business_id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('YouCan store connected.')]);

        return to_route('stores.connected', $store);
    }
}
