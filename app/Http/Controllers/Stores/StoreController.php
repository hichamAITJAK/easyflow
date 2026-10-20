<?php

namespace App\Http\Controllers\Stores;

use App\Enums\EcomPlatform;
use App\Enums\StoreConnectionStatus;
use App\Events\Store\StoreDeleting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stores\DeleteStoreRequest;
use App\Models\EcommercePlatform;
use App\Models\Store;
use App\Services\Operations\EcomPlatforms\LightfunnelsService;
use App\Services\Operations\EcomPlatforms\ShopifyService;
use App\Services\Operations\EcomPlatforms\YouCanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class StoreController extends Controller
{
    /**
     * Display the list of connected stores.
     *
     * Selects only the columns the list view renders (name, logo, status,
     * platform name — not meta/credentials/timestamps/etc.) to keep the
     * payload proportional to what's actually shown.
     */
    public function index(Request $request): Response
    {
        $stores = Store::query()
            ->select(['id', 'name', 'logo_url', 'description', 'domain', 'connection_status', 'last_synced_at', 'platform_id'])
            // slug rides along so the list knows how a store is reconnected:
            // OAuth platforms redirect out, the rest re-enter credentials.
            ->with('platform:id,name,slug')
            ->where('business_id', $request->user()->business_id)
            ->latest()
            ->get();

        return Inertia::render('stores/index', [
            'stores' => $stores,
        ]);
    }

    /**
     * Show the screen to pick a platform and connect a new store.
     */
    public function create(Request $request): Response
    {
        abort_if($request->user()->business_id === null, 403);

        // Arrives when a merchant chose "Reconnect" on a store whose
        // platform has no OAuth redirect: the credential form lives here, so
        // the page opens it on the right platform rather than making them
        // find it again. Scoped to the business so another tenant's store id
        // cannot be probed through this parameter.
        $reconnecting = $request->integer('reconnect') > 0
            ? Store::where('business_id', $request->user()->business_id)
                ->with('platform:id,name,slug')
                ->find($request->integer('reconnect'))
            : null;

        return Inertia::render('stores/create', [
            // The Manual platform has its own card, not a connect flow.
            'platforms' => EcommercePlatform::where('slug', '!=', 'manual')->get(),
            'reconnecting' => $reconnecting === null ? null : [
                'id' => $reconnecting->id,
                'name' => $reconnecting->name,
                'platform_slug' => $reconnecting->platform?->slug,
            ],
        ]);
    }

    /**
     * Create a store with no online shop. A brand that only sells through
     * WhatsApp, Instagram or the phone still needs a store so its manual
     * orders can be told apart from another brand's, filtered, and counted
     * in the dashboard breakdown. Nothing to connect: `manual` status keeps
     * it out of every platform sync.
     */
    public function storeManual(Request $request): RedirectResponse
    {
        $businessId = $request->user()->business_id;
        abort_if($businessId === null, 403);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $platform = EcommercePlatform::firstOrCreate(['slug' => 'manual'], ['name' => 'Manual']);

        // Slugs are unique per business; a second "Faizat" becomes faizat-2.
        $base = Str::slug($data['name']) ?: 'store';
        $slug = $base;

        for ($i = 2; Store::where('business_id', $businessId)->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        Store::create([
            'business_id' => $businessId,
            'platform_id' => $platform->id,
            'name' => trim($data['name']),
            'slug' => $slug,
            'api_credentials' => '[]',
            'connection_status' => StoreConnectionStatus::MANUAL,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Manual store created.')]);

        return redirect()->route('stores.index');
    }

    /**
     * Show the "store connected" confirmation screen after the OAuth
     * redirect back, so the merchant isn't left wondering whether the
     * connection actually worked.
     */
    public function connected(Request $request, Store $store): Response
    {
        abort_unless($store->business_id === $request->user()->business_id, 403);

        return Inertia::render('stores/connected', [
            'store' => $store->load('platform'),
        ]);
    }

    /**
     * Re-enter the connect flow for an existing store whose credentials
     * stopped working.
     *
     * OAuth platforms are sent back through their own authorization flow;
     * the callback recognises the store by its external id and refreshes the
     * credentials in place (see CreatesConnectedStore), so the store keeps
     * its products, orders and rules.
     *
     * Platforms where the merchant pastes credentials have no redirect to
     * send them to — the stores page opens its dialog for those instead, so
     * reaching this endpoint for one is a bad request rather than a flow.
     */
    public function reconnect(Request $request, Store $store): RedirectResponse
    {
        abort_unless($store->business_id === $request->user()->business_id, 403);

        $platform = EcomPlatform::tryFrom($store->platform->slug);

        abort_if($platform === null, 404);

        $url = match ($platform) {
            EcomPlatform::YOUCAN => (new YouCanService)->connect($store->business_id),
            EcomPlatform::LIGHTFUNNELS => (new LightfunnelsService)->connect($store->business_id),
            // The shop domain is what Shopify authorizes against, and this
            // store already knows its own — the merchant should not have to
            // retype it to fix a connection they already made.
            EcomPlatform::SHOPIFY => (new ShopifyService)->connect(
                $store->business_id,
                $this->shopifyDomainFor($store),
            ),
            default => null,
        };

        if ($url === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This platform is reconnected by re-entering its credentials.'),
            ]);

            return to_route('stores.index');
        }

        return redirect()->away($url);
    }

    /**
     * The `*.myshopify.com` domain a Shopify store was connected with.
     *
     * ShopifyConnectionController stores that domain as `external_store_id`
     * (see its createStoreOrRedirectBack call). `domain` is the storefront
     * domain, which may be a custom one Shopify's OAuth will not accept, so
     * it is not a substitute.
     */
    private function shopifyDomainFor(Store $store): string
    {
        $domain = $store->external_store_id;

        abort_if($domain === null || $domain === '', 422, __('This store has no Shopify domain on record.'));

        return $domain;
    }

    /**
     * Disconnect and delete a store.
     */
    public function destroy(DeleteStoreRequest $request, Store $store): RedirectResponse
    {
        // Ownership and the typed-name confirmation are both enforced by
        // DeleteStoreRequest before this runs.

        // Dispatched (and handled) before delete() — listeners that need to
        // make one last authenticated call against the platform (e.g.
        // unsubscribing a webhook) must run while credentials still exist.
        //
        // A failure here must not abort the deletion. The remote call can
        // fail for reasons entirely outside the merchant's control (the
        // platform is down, the token was already revoked on their side),
        // and a store that cannot be removed is worse than a webhook left
        // registered against a store that no longer accepts its deliveries.
        try {
            StoreDeleting::dispatch($store);
        } catch (Throwable $exception) {
            report($exception);
        }

        // The cascade spans several tables (products and their variants,
        // commission rules, agent scopes, stats reasons) and orders are
        // re-pointed to null. A partial run would leave the business with
        // half a store, so it either all happens or none of it does.
        DB::transaction(fn () => $store->delete());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Store deleted.')]);

        return to_route('stores.index');
    }
}
