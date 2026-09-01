<?php

namespace App\Services\Connectivity\Lightfunnels;

use Illuminate\Support\Facades\Http;

/**
 * Handles all Lightfunnels OAuth-based authentication flows.
 *
 * Docs (Postman): Lightfunnels API V2 collection, "auth / consent screen"
 * and "auth / get access token" requests.
 */
class LightfunnelsAuthService
{
    /**
     * Build the OAuth consent screen URL to redirect the merchant to.
     *
     * The merchant visits this URL to grant your app access to their store.
     *
     * @param  string  $clientId  Your app's client ID
     * @param  string  $redirectUri  The URL Lightfunnels will redirect back to after authorization
     * @param  array<int, string>  $scopes  Requested scopes, e.g. ['products', 'orders']
     * @param  string  $accountId  The merchant's Lightfunnels account id
     * @param  string  $state  A random nonce for CSRF protection
     * @return string The full authorization URL
     */
    public static function getAuthorizationUrl(
        string $clientId,
        string $redirectUri,
        array $scopes,
        string $accountId,
        string $state
    ): string {
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', $scopes),
            'account-id' => $accountId,
            'state' => $state,
            'response_type' => 'code',
        ]);

        return "https://app.lightfunnels.com/admin/oauth?{$query}";
    }

    /**
     * Exchange an OAuth authorization code for an access token.
     *
     * POST https://api.lightfunnels.com/oauth/access_token
     *
     * @return array{access_token: string, token_type?: string, expires_in?: int, refresh_token?: string}
     */
    public static function exchangeToken(
        string $clientId,
        string $clientSecret,
        string $code
    ): array {
        return Http::post('https://api.lightfunnels.com/oauth/access_token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
        ])->throw()->json();
    }
}
