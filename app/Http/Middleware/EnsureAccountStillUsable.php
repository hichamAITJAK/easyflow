<?php

namespace App\Http\Middleware;

use App\Actions\Fortify\DetermineAccountBlockReason;
use App\Enums\LoginContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks, on every authenticated request, the same conditions the login
 * pipeline checks once (see DetermineAccountBlockReason).
 *
 * Without this, suspending or cancelling a business only takes effect at the
 * tenant's *next* login — everyone already signed in keeps working until
 * their session happens to expire. A suspension is meant to stop work now,
 * so the check has to run per-request, not per-login.
 */
class EnsureAccountStillUsable
{
    public function __construct(
        protected DetermineAccountBlockReason $blockReason,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Mobile requests still carry the MOBILE context. No role is barred
        // by surface any more (see DetermineAccountBlockReason), so this no
        // longer changes the outcome, but the distinction is kept because
        // the context is part of that action's contract and the mobile auth
        // controllers pass it explicitly.
        $context = $request->is('api/mobile/*')
            ? LoginContext::MOBILE
            : LoginContext::WEB;

        $reason = ($this->blockReason)($user, $context);

        if ($reason === null) {
            return $next($request);
        }

        return $this->reject($request, $reason);
    }

    /**
     * Tear down whichever credential the request arrived with, then send the
     * user somewhere that explains why.
     */
    protected function reject(Request $request, string $reason): Response
    {
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['reason' => $reason], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('account-status', ['reason' => $reason]);
    }
}
