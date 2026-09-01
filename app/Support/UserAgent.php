<?php

namespace App\Support;

/**
 * Minimal user-agent labelling for the "where am I signed in" list.
 *
 * Deliberately not a full UA-parsing library: this only needs to produce a
 * label a person recognises as their own device ("Chrome on macOS"), and a
 * dependency that ships a browser database would be a lot of weight for
 * one settings panel. Unknown agents degrade to "Unknown device" rather
 * than guessing.
 */
class UserAgent
{
    /**
     * Browsers, most specific first — Edge and Opera both claim "Chrome",
     * and Chrome claims "Safari", so order is what makes this correct.
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Chrome/' => 'Chrome',
        'Firefox/' => 'Firefox',
        'Safari/' => 'Safari',
    ];

    /**
     * Platforms, most specific first — Android contains "Linux", and iPadOS
     * reports "Mac OS X" on some versions.
     *
     * @var array<string, string>
     */
    private const PLATFORMS = [
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'Windows' => 'Windows',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    /**
     * A human label for a session's user agent, e.g. "Chrome on macOS".
     */
    public static function describe(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return __('Unknown device');
        }

        $browser = self::match($userAgent, self::BROWSERS);
        $platform = self::match($userAgent, self::PLATFORMS);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => __('Unknown device'),
        };
    }

    /**
     * Whether the agent looks like a phone or tablet, so the list can show
     * the right icon.
     */
    public static function isMobile(?string $userAgent): bool
    {
        if ($userAgent === null) {
            return false;
        }

        foreach (['Android', 'iPhone', 'iPad', 'Mobile'] as $needle) {
            if (str_contains($userAgent, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $candidates
     */
    private static function match(string $userAgent, array $candidates): ?string
    {
        foreach ($candidates as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
