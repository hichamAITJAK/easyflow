<?php

namespace App\Services;

use PostHog\PostHog;

class PostHogService
{
    protected static bool $initialized = false;

    public function __construct()
    {
        if (config('posthog.disabled')) {
            return;
        }

        $apiKey = config('posthog.api_key');

        if (! self::$initialized) {
            if (! $apiKey) {
                if (config('app.debug')) {
                    throw new \RuntimeException(
                        'POSTHOG_PROJECT_TOKEN variable required by PostHog is missing or un-configured, '
                        .'this causes events to be silently missed. '
                        .'This error stops appearing once POSTHOG_PROJECT_TOKEN is configured.'
                    );
                }

                return;
            }

            // Short timeouts: an unreachable or slow PostHog host would
            // otherwise stall the request it is riding on until the browser
            // or proxy gives up, surfacing as a bare network error.
            PostHog::init($apiKey, [
                'host' => config('posthog.host'),
                'debug' => config('posthog.debug'),
                'timeout' => config('posthog.timeout'),
                'connect_timeout' => config('posthog.connect_timeout'),
            ]);

            self::$initialized = true;
        }
    }

    /** @param array<string, mixed> $properties */
    public function identify(string $distinctId, array $properties = []): void
    {
        if (config('posthog.disabled') || ! self::$initialized) {
            return;
        }

        try {
            PostHog::identify([
                'distinctId' => $distinctId,
                'properties' => $properties,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param array<string, mixed> $properties */
    public function capture(string $distinctId, string $event, array $properties = []): void
    {
        if (config('posthog.disabled') || ! self::$initialized) {
            return;
        }

        // The PHP client's default consumer flushes synchronously over the
        // network inside the request. Analytics must never be able to hang
        // or fail a user-facing write, so every send is best-effort.
        try {
            PostHog::capture([
                'distinctId' => $distinctId,
                'event' => $event,
                'properties' => $properties,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function captureException(\Throwable $exception, ?string $distinctId = null): ?string
    {
        if (config('posthog.disabled') || ! self::$initialized) {
            return null;
        }

        $distinctId = $distinctId ?? 'anonymous';
        $eventId = uniqid('error_', true);

        try {
            PostHog::captureException($exception, $distinctId, [
                'error_id' => $eventId,
            ]);
        } catch (\Throwable $e) {
            // Swallowed rather than reported: reporting an exception raised
            // while reporting an exception is how you get a loop.
            return null;
        }

        return $eventId;
    }
}
