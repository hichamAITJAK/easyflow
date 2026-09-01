<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'toast' => fn () => Inertia::getFlashed($request)['toast'] ?? null,
            'subscription' => fn () => $this->subscriptionState($request),
        ];
    }

    /**
     * Compact subscription state for tenant users, used by the renewal /
     * grace-period banners. Null for guests and super admins.
     *
     * @return array<string, mixed>|null
     */
    protected function subscriptionState(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null || $user->role === UserRole::SUPER_ADMIN || $user->business === null) {
            return null;
        }

        $subscription = $user->business->usableSubscription();

        if ($subscription === null) {
            return null;
        }

        return [
            'status' => $subscription->status->value,
            'isTrial' => $subscription->plan_id === null,
            'endsAt' => $subscription->ends_at?->toDateString(),
            'daysRemaining' => $subscription->daysRemaining(),
            'inGracePeriod' => $subscription->isInGracePeriod(),
            'graceEndsAt' => $subscription->plan_id !== null && $subscription->ends_at !== null
                ? $subscription->graceEndsAt()->toDateString()
                : null,
        ];
    }
}
