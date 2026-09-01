<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the tenant app for a business with no usable subscription (no
 * running trial, no paid cycle within its grace window) and sends it to
 * the subscription block screen to pick a plan / submit a payment claim.
 *
 * Super admins never pass through here — they live in /super-admin, which
 * this middleware is not applied to.
 */
class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->role === UserRole::SUPER_ADMIN) {
            return $next($request);
        }

        $business = $user->business;

        if ($business === null || $business->usableSubscription() !== null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_PAYMENT_REQUIRED, 'Subscription required.');
        }

        return redirect()->route('subscription.blocked');
    }
}
