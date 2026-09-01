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

        // Mobile requests carry the MOBILE context so a fulfilment agent
        // isn't bounced out of the app they're meant to use — the web-only
        // role rule must not apply there. Decided from the URL because the
        // mobile API is its own route file under that prefix.
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
