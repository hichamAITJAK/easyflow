<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Interfaces\PushNotifierInterface;
use App\Models\User;
use App\Services\Connectivity\Expo\ExpoPushNotifier;
use App\Services\Connectivity\Expo\NullPushNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerPushNotifier();
    }

    /**
     * Resolve the push transport once, here, so every consumer (channels,
     * listeners, commands) type-hints PushNotifierInterface and stays
     * unaware of Expo.
     *
     * Singleton because the underlying HTTP factory is reusable and a
     * single request can fan a notification out to several devices —
     * rebuilding the client per resolution would buy nothing.
     */
    private function registerPushNotifier(): void
    {
        $this->app->singleton(PushNotifierInterface::class, function ($app): PushNotifierInterface {
            /** @var array{enabled: bool, endpoint: string, timeout: int, access_token: ?string} $config */
            $config = config('services.expo');

            if (! $config['enabled']) {
                return new NullPushNotifier;
            }

            return new ExpoPushNotifier(
                $app->make(HttpFactory::class),
                $config['endpoint'],
                $config['timeout'],
                $config['access_token'] ?? null,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->configureWebhookRateLimiting();
    }

    /**
     * Rate limits for inbound platform webhooks (PRD: "Inbound webhooks and
     * the platform's own API are rate-limited per tenant/per store, not just
     * per IP").
     *
     * Per store, not per IP: every delivery from one platform arrives from
     * that platform's own egress addresses, so an IP limit would either be
     * loose enough to be useless or would let one noisy store throttle every
     * other store sharing those addresses.
     *
     * The ceiling is deliberately far above real traffic. A 429 is not data
     * loss — Shopify, YouCan and WooCommerce all retry on non-2xx — but a
     * retry storm is still worse than absorbing the burst, so the limit is
     * set to catch runaway loops and abuse rather than to shape legitimate
     * volume. 300/minute is 5 orders per second sustained for a single
     * store, which no real COD store reaches.
     */
    protected function configureWebhookRateLimiting(): void
    {
        RateLimiter::for('webhook-store', function (Request $request) {
            /*
             * The raw {store} path segment, NOT a resolved Store model.
             *
             * Laravel runs group middleware before route middleware, so
             * ThrottleRequests is invoked ahead of SubstituteBindings no
             * matter how the two are ordered at the call site — the binding
             * has not happened yet. Reading $request->route('store') and
             * testing it with `instanceof Store` therefore never matches,
             * and every request would quietly fall through to a per-IP key,
             * which is the exact behaviour the PRD rules out.
             *
             * Casting through (int) normalises the segment so "7", "07" and
             * "7 " all land in one bucket rather than three, and a
             * non-numeric segment collapses to 0 — a single shared bucket
             * for junk ids, which is correct: they can never resolve to a
             * store, and giving each its own bucket would let one sender
             * mint unlimited buckets by varying the path.
             */
            $storeId = (int) $request->route('store');

            // A junk id gets keyed by IP instead, so one sender spraying
            // unresolvable ids can't consume the shared 0-bucket that other
            // senders' typos would also land in.
            $key = $storeId > 0
                ? 'store:'.$storeId
                : 'ip:'.$request->ip();

            return Limit::perMinute(300)->by($key);
        });

        /*
         * Shopify's mandatory compliance webhooks carry no {store} — they are
         * shop-scoped and resolved from a header — so there is no tenant to
         * key on and this falls back to the IP.
         *
         * Kept generous on purpose: Shopify's automated app-review checks POST
         * to these endpoints, and answering one of those with a 429 fails the
         * review. They are low-volume by nature, so this only has to stop
         * someone hammering them.
         */
        RateLimiter::for('webhook-compliance', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }

    /**
     * Define the application's authorization gates.
     */
    protected function configureGates(): void
    {
        Gate::define('manage-users', fn (User $user): bool => in_array(
            $user->role,
            [UserRole::SUPER_ADMIN, UserRole::ADMIN],
            true,
        ));

        // Blacklisting is separated from `manage-users` so a confirmation
        // agent can block a prank caller during the call itself, without also
        // inheriting bulk delete, bulk status and assignment — the rest of
        // what `manage-users` guards on the orders routes.
        Gate::define('blacklist-customers', fn (User $user): bool => in_array(
            $user->role,
            [UserRole::SUPER_ADMIN, UserRole::ADMIN, UserRole::CONFIRMATION_AGENT],
            true,
        ));

        // Agents read the catalogue (scoped to their AgentScope grants, see
        // ScopesAgentAccess) so they can look a product up mid-call, but the
        // catalogue itself is the admin's to shape — no create, edit or
        // delete, and no syncing from a connected store.
        Gate::define('manage-products', fn (User $user): bool => in_array(
            $user->role,
            [UserRole::SUPER_ADMIN, UserRole::ADMIN],
            true,
        ));

        Gate::define('manage-platform', fn (User $user): bool => $user->role === UserRole::SUPER_ADMIN);

        // The scan-driven fulfilment workspace (UC-16/UC-17). Admins get it
        // too so they can cover the warehouse or verify a scan without
        // borrowing an agent's account.
        Gate::define('handle-fulfilment', fn (User $user): bool => in_array(
            $user->role,
            [UserRole::ADMIN, UserRole::FULFILMENT_AGENT],
            true,
        ));

        // The tenant app (dashboard, orders, stores, ...) is off-limits to a
        // super admin — they have no business_id and belong in /super-admin.
        Gate::define('access-tenant-app', fn (User $user): bool => $user->role !== UserRole::SUPER_ADMIN);

        // The ordinary operational screens — dashboard, orders, products,
        // customers. A fulfilment agent works a single scan workspace and
        // has no business browsing these: their order visibility is scoped
        // to assignments they never receive, so without this they would get
        // an empty list rather than a refusal, which reads as a bug. They
        // keep their own commission entries, which are gated separately.
        Gate::define('use-operations-app', fn (User $user): bool => $user->role !== UserRole::FULFILMENT_AGENT);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
