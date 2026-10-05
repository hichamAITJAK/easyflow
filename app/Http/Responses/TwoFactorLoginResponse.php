<?php

namespace App\Http\Responses;

use App\Actions\Fortify\DetermineAccountBlockReason;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(
        protected StatefulGuard $guard,
        protected DetermineAccountBlockReason $blockReason,
    ) {}

    public function toResponse($request)
    {
        /** @var User $user */
        $user = $this->guard->user();

        $reason = ($this->blockReason)($user);

        if ($reason !== null) {
            return $this->blockedResponse($request, $reason);
        }

        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        // See LoginResponse: a super admin must not follow a stored
        // intended URL into the tenant app, which is closed to them.
        if ($user->role === UserRole::SUPER_ADMIN) {
            $request->session()->forget('url.intended');

            return redirect()->route('super-admin.businesses.index');
        }

        // Likewise a fulfilment agent, whose only web surface is the scan
        // workspace — a stored intended URL would point at a page that
        // logs them straight back out.
        if ($user->role === UserRole::FULFILMENT_AGENT) {
            $request->session()->forget('url.intended');

            return redirect()->route('fulfillment.index');
        }

        return redirect()->intended(Fortify::redirects('login'));
    }

    protected function blockedResponse(Request $request, string $reason): mixed
    {
        $this->guard->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $request->wantsJson()
            ? new JsonResponse(['reason' => $reason], 403)
            : redirect()->route('account-status', ['reason' => $reason]);
    }
}
