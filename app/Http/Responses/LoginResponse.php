<?php

namespace App\Http\Responses;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class LoginResponse implements LoginResponseContract
{
    public function __construct(
        protected StatefulGuard $guard,
        protected PostHogService $posthog,
    ) {}

    /**
     * Redirect a super admin into the platform panel instead of the tenant
     * dashboard; every other role keeps Fortify's normal intended-url flow.
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        /** @var User $user */
        $user = $this->guard->user();

        // PostHog: Identify and track login
        $this->posthog->identify((string) $user->id, [
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role->value,
            'business_id' => $user->business_id,
        ]);
        $this->posthog->capture((string) $user->id, 'user_logged_in', [
            'role' => $user->role->value,
            'login_method' => 'password',
        ]);

        if ($user->role === UserRole::SUPER_ADMIN) {
            return redirect()->intended(route('super-admin.businesses.index'));
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
