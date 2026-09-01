<?php

namespace App\Support;

/**
 * Pulls the courier tracking number out of a raw scanned QR/barcode
 * payload (UC-17).
 *
 * Most couriers encode the bare tracking number, so the raw scanned value
 * IS the answer in the common case. This class exists for the rest: the
 * couriers that wrap it in something else — usually a tracking URL, with
 * the number either in a query parameter or as the last path segment.
 *
 * Deliberately courier-agnostic. The fulfilment scan happens before we
 * know which courier printed a label — there's no account to build a
 * service from, and a bare tracking number carries no marker saying whose
 * it is — so extraction can't be dispatched to a per-courier
 * implementation. It's pattern recognition on the payload, not courier
 * logic, and it lives in one place accordingly.
 *
 * Strict by design: it only deviates from the raw value when a known
 * pattern clearly matches. An unrecognised payload is returned trimmed
 * and otherwise untouched, so a courier whose format nobody has taught us
 * still scans correctly as long as it encodes the number plainly. Adding
 * a new courier's quirk means adding a rule here, not touching the
 * fulfilment flow.
 */
class TrackingNumberExtractor
{
    /**
     * Query parameters couriers are known to carry a tracking number in.
     * Ordered most- to least-specific: a link that has both `dn-ref` and a
     * generic `ref` means the former, since only a courier that uses
     * `dn-ref` emits it at all.
     */
    private const TRACKING_QUERY_KEYS = ['dn-ref', 'tracking-number', 'trackingnumber', 'tracking', 'code', 'ref', 'n'];

    /**
     * The tracking number a scanned value represents.
     *
     * Never returns null: an unrecognised payload falls back to its own
     * trimmed self, which is both the common case and the safest guess.
     * A caller that finds no order for the result should report "unknown
     * code", not assume extraction failed.
     */
    public static function extract(string $scannedValue): string
    {
        $value = trim($scannedValue);

        return self::fromUrl($value) ?? $value;
    }

    /**
     * Read the tracking number out of a scanned URL — a known tracking
     * query parameter first, then the last path segment, which is the
     * shape most courier tracking links use.
     *
     * Returns null when the payload isn't a URL, or is one that carries no
     * usable value (a bare domain, say), leaving the caller on the raw
     * value.
     */
    private static function fromUrl(string $value): ?string
    {
        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);

        foreach (self::TRACKING_QUERY_KEYS as $key) {
            $candidate = isset($query[$key]) && is_string($query[$key]) ? trim($query[$key]) : '';

            if ($candidate !== '') {
                return $candidate;
            }
        }

        $path = trim($parts['path'] ?? '', '/');

        if ($path === '') {
            return null;
        }

        $segment = basename($path);

        return $segment === '' ? null : $segment;
    }
}
