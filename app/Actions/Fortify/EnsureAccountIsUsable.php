<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

class EnsureAccountIsUsable
{
    public function __construct(
        protected StatefulGuard $guard,
        protected DetermineAccountBlockReason $blockReason,
    ) {}

    /**
     * Reject logins for users or businesses that aren't in a usable state,
     * redirecting to a status page instead of letting the session through.
     */
    public function handle(Request $request, callable $next): mixed
    {
        /** @var User $user */
        $user = $this->guard->user();

        $reason = ($this->blockReason)($user);

        if ($reason === null) {
            return $next($request);
        }

        $this->guard->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('account-status', ['reason' => $reason]);
    }
}
