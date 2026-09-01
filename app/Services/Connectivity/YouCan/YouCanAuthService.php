<?php

namespace App\Services\Connectivity\YouCan;

/**
 * Handles all YouCan authentication-related API calls.
 *
 * Inputs:  credentials passed to constructor
 * Outputs: associative array from the API response
 */
class YouCanAuthService extends YouCanHttpClient
{
    /**
     * Authenticate with email + password and receive an access token.
     *
     * @return array{token: string, user: array<string, mixed>}
     */
    public function login(string $email, string $password): array
    {
        $response = $this->post('/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        return [
            'token' => $response['token'],
            'user' => $response['user'],
        ];
    }

    /**
     * Exchange an OAuth authorization code (or refresh token) for an access token.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     */
    public function exchangeToken(
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri,
        string $grantType = 'authorization_code'
    ): array {
        $response = $this->post('/oauth/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => $grantType,
        ]);

        return [
            'access_token' => $response['access_token'],
            'token_type' => $response['token_type'],
            'expires_in' => $response['expires_in'],
            'refresh_token' => $response['refresh_token'],
        ];
    }

    /**
     * Build the OAuth authorization URL to redirect the user to.
     *
     * @param  array<int, string>  $scopes  Requested scopes, e.g. ['*'] for full access
     * @return string The full authorization URL
     */
    public function getAuthorizationUrl(
        string $clientId,
        string $redirectUri,
        string $responseType = 'code',
        array $scopes = ['*']
    ): string {
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => $responseType,
            'scope' => implode(' ', $scopes),
        ]);

        return 'https://seller-area.youcan.shop/admin/oauth/authorize?'.$query;
    }

    /**
     * Switch the active store context for the authenticated user.
     *
     * @return array{token: string}
     */
    public function switchStore(string $storeId): array
    {
        $response = $this->post('/auth/switch-store', [
            'store_id' => $storeId,
        ]);

        return [
            'token' => $response['token'],
        ];
    }

    /**
     * Invalidate the current access token (logout).
     *
     * @return array{message: string}
     */
    public function logout(): array
    {
        $response = $this->post('/auth/logout');

        return [
            'message' => $response['message'],
        ];
    }
}
