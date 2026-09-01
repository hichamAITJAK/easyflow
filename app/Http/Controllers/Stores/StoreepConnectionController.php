<?php

namespace App\Http\Controllers\Stores;

use App\Enums\EcomPlatform;
use App\Enums\StoreConnectionStatus;
use App\Events\Store\StoreConnected;
use App\Http\Controllers\Concerns\CreatesConnectedStore;
use App\Http\Controllers\Controller;
use App\Models\EcommercePlatform;
use App\Services\Connectivity\Storeep\StoreepClient;
use App\Services\PostHogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Connect a Storeep store.
 *
 * Unlike every other platform we integrate, Storeep has no OAuth flow —
 * the merchant creates an access token in their Storeep dashboard
 * (Settings → Access tokens) and pastes it into EasyFlow. So there is no
 * redirect out and no callback back: this single action receives the
 * token, proves it works, and creates the store.
 */
class StoreepConnectionController extends Controller
{
    use CreatesConnectedStore;

    /**
     * Validate the pasted access token against the live Storeep API, then
     * create the connected store.
     *
     * The token is proven before anything is persisted: a token that is
     * expired, malformed, or missing the `products:read` permission would
     * otherwise create a store that silently syncs nothing, and the failure
     * would only surface later in a queued job where the merchant never
     * sees it.
     */
    public function store(Request $request, PostHogService $posthog): RedirectResponse
    {
        abort_if($request->user()->business_id === null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'access_token' => ['required', 'string', 'max:255'],
            // Storeep prices per market; recording which one this store
            // sells in lets product sync pick the right pricing row.
            'market' => ['nullable', 'string', 'size:2', 'alpha'],
        ]);

        $accessToken = trim($validated['access_token']);
        $market = isset($validated['market']) ? strtoupper($validated['market']) : null;

        $this->assertTokenWorks($accessToken);

        $platform = EcommercePlatform::where('slug', EcomPlatform::STOREEP->value)->firstOrFail();

        $store = $this->createStoreOrRedirectBack([
            'business_id' => $request->user()->business_id,
            'platform_id' => $platform->id,
            // Storeep exposes no store id or store details endpoint, so
            // there is nothing to key the per-platform uniqueness constraint
            // on. The token itself is the store's identity — hashed, never
            // stored in plaintext outside the encrypted credentials blob —
            // so reconnecting with the same token is correctly rejected as a
            // duplicate rather than creating a second store row.
            'external_store_id' => hash('sha256', $accessToken),
            'name' => $validated['name'],
            'logo_url' => $platform->logo_url,
            'meta' => ['market' => $market],
            'api_credentials' => json_encode(['access_token' => $accessToken]),
            'connection_status' => StoreConnectionStatus::CONNECTED,
        ]);

        if ($store instanceof RedirectResponse) {
            return $store;
        }

        StoreConnected::dispatch($store);

        $posthog->capture((string) $request->user()->id, 'store_connected', [
            'platform' => 'storeep',
            'store_name' => $store->name,
            'business_id' => $store->business_id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Storeep store connected.')]);

        return to_route('stores.connected', $store);
    }

    /**
     * Prove the pasted token can actually read this store's catalog,
     * translating Storeep's rejections into a field-level error on the
     * token input rather than a bare 500 or a silently broken store.
     *
     * @throws ValidationException
     */
    private function assertTokenWorks(string $accessToken): void
    {
        try {
            (new StoreepClient($accessToken))->products()->listProducts(['limit' => 1]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'access_token' => __('Could not reach Storeep — check your connection and try again.'),
            ]);
        } catch (RequestException $e) {
            throw ValidationException::withMessages([
                'access_token' => match ($e->response->status()) {
                    401 => __('Storeep rejected that access token. Check you copied it correctly and that it hasn\'t expired.'),
                    403 => __('That access token is missing the "products:read" permission. Add it in Storeep, then try again.'),
                    429 => __('Storeep is rate limiting this token. Wait a minute, then try again.'),
                    default => __('Storeep rejected the connection. Please try again.'),
                },
            ]);
        } catch (\Throwable $e) {
            Log::warning('Storeep token validation failed', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'access_token' => __('Could not verify that access token. Please try again.'),
            ]);
        }
    }
}
