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

            PostHog::init($apiKey, [
                'host' => config('posthog.host'),
                'debug' => config('posthog.debug'),
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

        PostHog::identify([
            'distinctId' => $distinctId,
            'properties' => $properties,
        ]);
    }

    /** @param array<string, mixed> $properties */
    public function capture(string $distinctId, string $event, array $properties = []): void
    {
        if (config('posthog.disabled') || ! self::$initialized) {
            return;
        }

        PostHog::capture([
            'distinctId' => $distinctId,
            'event' => $event,
            'properties' => $properties,
        ]);
    }

    public function captureException(\Throwable $exception, ?string $distinctId = null): ?string
    {
        if (config('posthog.disabled') || ! self::$initialized) {
            return null;
        }

        $distinctId = $distinctId ?? 'anonymous';
        $eventId = uniqid('error_', true);

        PostHog::captureException($exception, $distinctId, [
            'error_id' => $eventId,
        ]);

        return $eventId;
    }
}
