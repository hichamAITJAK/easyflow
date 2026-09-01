<?php

namespace App\Services\Connectivity\Shopify;

use Illuminate\Support\Facades\Http;

/**
 * Handles all Shopify OAuth-based authentication flows.
 *
 * Shopify does NOT support username/password login via API.
 * Authentication is token-based (OAuth 2.0 or admin-generated tokens).
 *
 * Docs: https://shopify.dev/docs/apps/auth/oauth
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the API response
 */
class ShopifyAuthService extends ShopifyHttpClient
{
    /**
     * Build the OAuth authorization URL to redirect the merchant to.
     *
     * The merchant visits this URL to grant your app access to their store.
     *
     * @param  string  $shop  The myshopify domain slug (e.g. "my-store")
     * @param  string  $clientId  Your app's API key / client ID
     * @param  string  $redirectUri  The URL Shopify will redirect back to after authorization
     * @param  array<int, string>  $scopes  Array of access scopes, e.g. ['read_orders', 'write_products']
     * @param  string  $state  A random nonce for CSRF protection
     * @return string The full authorization URL
     */
    public static function getAuthorizationUrl(
        string $shop,
        string $clientId,
        string $redirectUri,
        array $scopes,
        string $state
    ): string {
        $shop = str_replace('.myshopify.com', '', $shop);
        $query = http_build_query([
            'client_id' => $clientId,
            'scope' => implode(',', $scopes),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return "https://{$shop}.myshopify.com/admin/oauth/authorize?{$query}";
    }

    /**
     * Exchange an OAuth authorization code for a permanent access token.
     *
     * Call this from your redirect URI handler after the merchant authorizes.
     * This is a static method because it requires no pre-existing token.
     *
     * POST https://{shop}.myshopify.com/admin/oauth/access_token
     *
     * @param  string  $shop  The myshopify domain slug
     * @param  string  $clientId  Your app's API key / client ID
     * @param  string  $clientSecret  Your app's API secret / client secret
     * @param  string  $code  The authorization code from the OAuth callback
     * @return array{access_token: string, scope: string}
     */
    public static function exchangeToken(
        string $shop,
        string $clientId,
        string $clientSecret,
        string $code
    ): array {
        $shop = str_replace('.myshopify.com', '', $shop);

        return Http::post(
            "https://{$shop}.myshopify.com/admin/oauth/access_token",
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'expiring' => '1',
            ]
        )->throw()->json();
    }

    /**
     * Exchange a refresh token for a new offline access token.
     *
     * Only applicable to offline tokens requested with `expiring=1` —
     * Shopify returns a `refresh_token` alongside the `access_token` in
     * that case, since the access token itself expires (`expires_in`).
     *
     * POST https://{shop}.myshopify.com/admin/oauth/access_token
     *
     * @param  string  $shop  The myshopify domain slug
     * @param  string  $clientId  Your app's API key / client ID
     * @param  string  $clientSecret  Your app's API secret / client secret
     * @param  string  $refreshToken  The refresh_token from a previous token exchange
     * @return array{access_token: string, scope: string, expires_in: int, refresh_token: string, refresh_token_expires_in: int}
     */
    public static function refreshToken(
        string $shop,
        string $clientId,
        string $clientSecret,
        string $refreshToken
    ): array {
        $shop = str_replace('.myshopify.com', '', $shop);

        return Http::post(
            "https://{$shop}.myshopify.com/admin/oauth/access_token",
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]
        )->throw()->json();
    }

    /**
     * Verify an HMAC signature from a Shopify webhook or OAuth callback.
     *
     * Shopify includes a `hmac` parameter in every callback that should be
     * validated to ensure the request is genuinely from Shopify.
     *
     * @param  string  $clientSecret  Your app's API secret key
     * @param  array<string, mixed>  $params  The query parameters from the callback (excluding 'hmac')
     * @param  string  $hmac  The HMAC value provided by Shopify
     * @return bool True if the HMAC is valid
     */
    public static function verifyHmac(string $clientSecret, array $params, string $hmac): bool
    {
        ksort($params);
        $message = http_build_query($params);
        $computed = hash_hmac('sha256', $message, $clientSecret);

        return hash_equals($computed, $hmac);
    }
}
