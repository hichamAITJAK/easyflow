<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the interface language for this request.
 *
 * The account's choice wins so it follows the person to any device. A
 * guest, or an account that never chose, falls back to the cookie the
 * switcher sets before login, and then to the product default. Runs
 * before HandleInertiaRequests so the translations it shares are already
 * in the right language.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locale::fromMixed(
            $request->user()?->locale ?? $request->cookie(self::COOKIE),
        );

        app()->setLocale($locale->value);

        return $next($request);
    }
}
