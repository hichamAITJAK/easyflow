<?php

namespace App\Http\Controllers\Stores;

use App\Enums\EcomPlatform;
use App\Enums\StoreConnectionStatus;
use App\Events\Store\StoreConnected;
use App\Http\Controllers\Concerns\CreatesConnectedStore;
use App\Http\Controllers\Controller;
use App\Models\EcommercePlatform;
use App\Services\Connectivity\WooCommerce\WooCommerceClient;
use App\Services\Connectivity\WooCommerce\WooCommerceStoreUrl;
use App\Services\PostHogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Connect a WooCommerce store.
 *
 * WooCommerce is self-hosted and has no OAuth flow for third parties — the
 * merchant generates a consumer key/secret pair in WooCommerce > Settings >
 * Advanced > REST API and pastes it in, together with their site URL. So
 * there is no redirect out and no callback back: this single action
 * receives the credentials, proves they work, and creates the store.
 *
 * The site URL is merchant-supplied and is what our server will make HTTP
 * requests against, so it is vetted by WooCommerceStoreUrl before anything
 * is persisted or called — see that class for why.
 */
class WooCommerceConnectionController extends Controller
{
    use CreatesConnectedStore;

    /**
     * Validate the pasted credentials against the live store, then create
     * the connected store.
     *
     * The key pair is proven before anything is persisted: a key that is
     * revoked, mistyped, or read-only in the wrong way would otherwise
     * create a store that silently syncs nothing, and the failure would only
     * surface later in a queued job where the merchant never sees it.
     */
    public function store(Request $request, PostHogService $posthog): RedirectResponse
    {
        abort_if($request->user()->business_id === null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'store_url' => ['required', 'string', 'max:255'],
            'consumer_key' => ['required', 'string', 'max:255'],
            'consumer_secret' => ['required', 'string', 'max:255'],
        ]);

        $storeUrl = $this->normalizeStoreUrl($validated['store_url']);
        $consumerKey = trim($validated['consumer_key']);
        $consumerSecret = trim($validated['consumer_secret']);

        $this->assertCredentialsWork($storeUrl, $consumerKey, $consumerSecret);

        $platform = EcommercePlatform::where('slug', EcomPlatform::WOOCOMMERCE->value)->firstOrFail();

        $store = $this->createStoreOrRedirectBack([
            'business_id' => $request->user()->business_id,
            'platform_id' => $platform->id,
            // WooCommerce has no vendor-assigned store id — every install is
            // independent — so the site's own origin is its identity. That
            // makes the per-platform uniqueness constraint do the right
            // thing: reconnecting the same site is rejected as a duplicate
            // rather than creating a second store row, even if the merchant
            // generates a fresh key pair.
            'external_store_id' => $storeUrl,
            'name' => $validated['name'],
            'domain' => $storeUrl,
            'logo_url' => $platform->logo_url,
            'api_credentials' => json_encode([
                'store_url' => $storeUrl,
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
            ]),
            'connection_status' => StoreConnectionStatus::CONNECTED,
        ]);

        if ($store instanceof RedirectResponse) {
            return $store;
        }

        StoreConnected::dispatch($store);

        $posthog->capture((string) $request->user()->id, 'store_connected', [
            'platform' => 'woocommerce',
            'store_name' => $store->name,
            'business_id' => $store->business_id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('WooCommerce store connected.')]);

        return to_route('stores.connected', $store);
    }

    /**
     * Normalize and vet the merchant's site URL, turning a rejection into a
     * field-level error on the URL input.
     *
     * @throws ValidationException
     */
    private function normalizeStoreUrl(string $storeUrl): string
    {
        try {
            return WooCommerceStoreUrl::normalize($storeUrl);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'store_url' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Prove the pasted key pair can actually read this store, translating
     * WooCommerce's rejections into a field-level error rather than a bare
     * 500 or a silently broken store.
     *
     * The system status endpoint is used because it needs a read-capable
     * key, takes no parameters and has no side effects.
     *
     * @throws ValidationException
     */
    private function assertCredentialsWork(string $storeUrl, string $consumerKey, string $consumerSecret): void
    {
        try {
            (new WooCommerceClient($storeUrl, $consumerKey, $consumerSecret))->system()->status();
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'store_url' => __('Could not reach that store. Check the URL and that the site is online.'),
            ]);
        } catch (RequestException $e) {
            $status = $e->response->status();

            // 404 is the URL's problem (no WooCommerce REST API there);
            // 401/403 are the key pair's. Attaching each to the field the
            // merchant actually has to fix.
            if ($status === 404) {
                throw ValidationException::withMessages([
                    'store_url' => __('No WooCommerce API was found at that URL. Check the address, and that pretty permalinks are enabled in WordPress.'),
                ]);
            }

            throw ValidationException::withMessages([
                'consumer_key' => match ($status) {
                    401 => __('WooCommerce rejected those API keys. Check you copied both the key and the secret correctly.'),
                    403 => __('Those API keys do not have read access. Set the permissions to "Read" or "Read/Write" in WooCommerce, then try again.'),
                    429 => __('That store is rate limiting us. Wait a minute, then try again.'),
                    default => __('WooCommerce rejected the connection. Please try again.'),
                },
            ]);
        } catch (\Throwable $e) {
            Log::warning('WooCommerce credential validation failed', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'store_url' => __('Could not verify that store. Please try again.'),
            ]);
        }
    }
}
