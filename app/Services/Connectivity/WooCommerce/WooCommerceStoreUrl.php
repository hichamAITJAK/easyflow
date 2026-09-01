<?php

namespace App\Services\Connectivity\WooCommerce;

use InvalidArgumentException;

/**
 * Normalizes and vets a merchant-supplied WooCommerce site URL.
 *
 * WooCommerce is self-hosted, which makes it the only platform we integrate
 * where the merchant supplies the hostname we then make server-side HTTP
 * requests to. Shopify is always *.myshopify.com, and YouCan/Storeep/
 * Lightfunnels all live behind one fixed vendor API host — none of them let
 * a tenant point us anywhere.
 *
 * An unvetted host here is a server-side request forgery (SSRF) primitive:
 * a merchant could connect a "store" at http://169.254.169.254/ (the cloud
 * metadata endpoint), http://127.0.0.1:6379 (a local Redis), or an internal
 * 10.x address, and our own server would dutifully fetch it with our own
 * network position and echo the result back through connection errors.
 *
 * So a URL is accepted only if it:
 *   - parses, and carries a host;
 *   - is https (the WooCommerce docs require HTTPS for key/secret Basic
 *     Auth anyway — over plain HTTP the credentials would be readable in
 *     transit, and WooCommerce itself mandates OAuth 1.0a there);
 *   - uses the default port, or none;
 *   - is not an IP literal, and does not resolve to a private, loopback,
 *     link-local, or otherwise reserved range.
 *
 * The DNS check is done once here at connect time. That leaves a
 * theoretical DNS-rebinding window (the name could resolve elsewhere on a
 * later sync), which closing properly needs pinning at the socket layer —
 * out of scope here, and the practical exposure is small because every
 * later request carries the merchant's own credentials to a host they
 * already proved they control.
 */
final class WooCommerceStoreUrl
{
    /**
     * Normalize a merchant-entered site URL to a bare `https://host` origin.
     *
     * Accepts what merchants actually paste — `example.com`,
     * `https://example.com/`, `https://example.com/wp-json/wc/v3` — and
     * reduces all of them to the scheme+host we build request URLs from.
     *
     * @throws InvalidArgumentException with a merchant-facing reason.
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('Enter your store URL.');
        }

        // A bare domain has no scheme to parse; assume https rather than
        // rejecting, since that is how most people type a site address.
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new InvalidArgumentException('That does not look like a valid store URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host']);

        if ($scheme !== 'https') {
            throw new InvalidArgumentException(
                'Your store URL must start with https:// — WooCommerce requires HTTPS to accept API keys.'
            );
        }

        // A non-default port is both a smell (real storefronts run on 443)
        // and the shape an internal-service probe takes.
        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new InvalidArgumentException('Your store URL must not include a port number.');
        }

        self::assertHostIsPublic($host);

        return "https://{$host}";
    }

    /**
     * Reject hosts that point back at our own infrastructure.
     *
     * @throws InvalidArgumentException
     */
    private static function assertHostIsPublic(string $host): void
    {
        // Bracketed IPv6 literal, e.g. [::1].
        $candidate = trim($host, '[]');

        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            // An IP literal is never a real storefront — WooCommerce needs a
            // domain for its own site URL and cookie handling.
            throw new InvalidArgumentException('Enter your store\'s domain name, not an IP address.');
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            throw new InvalidArgumentException('We could not resolve that domain. Check the spelling and try again.');
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                throw new InvalidArgumentException('That store URL points to a private address, so we cannot reach it.');
            }
        }
    }

    /**
     * Every A/AAAA record for the host. All of them are checked, not just
     * the first: a host that resolves to both a public and a private
     * address must still be rejected.
     *
     * @return string[]
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        if ($addresses !== []) {
            return $addresses;
        }

        // dns_get_record can come back empty on resolvers that don't answer
        // the combined query; gethostbyname is the IPv4-only fallback. It
        // returns the input unchanged on failure, which is not an address.
        $resolved = gethostbyname($host);

        return $resolved !== $host ? [$resolved] : [];
    }

    /**
     * Whether an address is in public (routable) space.
     *
     * FILTER_FLAG_NO_PRIV_RANGE covers RFC1918 (10/8, 172.16/12, 192.168/16)
     * and IPv6 unique-local; FILTER_FLAG_NO_RES_RANGE covers loopback,
     * link-local (including 169.254.169.254, the cloud metadata address),
     * and the other reserved blocks.
     */
    private static function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
