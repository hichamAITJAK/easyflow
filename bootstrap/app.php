<?php

use App\Http\Middleware\EnsureAccountStillUsable;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Kept out of routes/api.php: the mobile app's /api/mobile
            // routes are a deliberately separate surface from the web
            // app's other API routes, so they can evolve (and be read)
            // independently as the mobile app grows.
            Route::middleware('api')
                ->prefix('api')
                ->group(__DIR__.'/../routes/api-mobile.php');

            // Inbound platform webhooks deliberately do not run the web
            // stack: session and cookie middleware would attach Set-Cookie
            // headers and render errors as full Inertia HTML pages.
            // Shopify's automated checks POST an unsigned request and expect
            // a small, clean 401 — a 12KB HTML error page with session
            // cookies fails that check.
            //
            // SubstituteBindings is what makes the {store} parameter a
            // model for the controllers. It does NOT run before the
            // throttle: Laravel invokes group middleware ahead of route
            // middleware, so the limiter sees the raw path segment and
            // keys on that instead — see configureWebhookRateLimiting().
            //
            // The throttle itself is per store rather than the 'api' group's
            // per-IP one — see AppServiceProvider::configureWebhookRateLimiting()
            // for the limits and why they are set where they are.
            Route::middleware([SubstituteBindings::class])
                ->group(__DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale']);

        // Incoming platform webhooks (Shopify, YouCan, ...) can't supply a
        // Laravel CSRF token — they're authenticated by their own signature
        // verification in the receiving controller instead.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Runs on every request rather than per route group, so a
            // suspended or cancelled business loses access immediately
            // instead of at its users' next login. No-ops for guests.
            EnsureAccountStillUsable::class,
        ]);

        // Same rule for the token-authenticated mobile API.
        $middleware->api(append: [
            EnsureAccountStillUsable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('webhooks/*')
                || $request->expectsJson(),
        );

        // Render HTTP errors (403, 404, 419, 429, 500, 503, ...) as Inertia
        // pages instead of Laravel's default error HTML, so a broken link or
        // expired session lands the user on a page styled like the rest of
        // the app rather than a bare Symfony/Laravel error screen.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();

            if (app()->hasDebugModeEnabled() && $status === 500) {
                return $response;
            }

            $errorPages = [403, 404, 419, 429, 500, 503];

            if (! in_array($status, $errorPages, true)) {
                return $response;
            }

            $messages = [
                403 => "You don't have permission to view this page.",
                404 => "The page you're looking for doesn't exist or may have been moved.",
                419 => 'Your session has expired. Please refresh the page and try again.',
                429 => "You've made too many requests. Please wait a moment and try again.",
                500 => 'An unexpected error occurred on our end. Please try again shortly.',
                503 => "We're performing scheduled maintenance. Please check back shortly.",
            ];

            $titles = [
                403 => 'Access denied',
                404 => 'Page not found',
                419 => 'Session expired',
                429 => 'Too many requests',
                500 => 'Something went wrong',
                503 => 'Down for maintenance',
            ];

            return Inertia::render("errors/{$status}", [
                'status' => $status,
                'title' => $titles[$status],
                'message' => $exception instanceof HttpExceptionInterface && $exception->getMessage()
                    ? $exception->getMessage()
                    : $messages[$status],
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
