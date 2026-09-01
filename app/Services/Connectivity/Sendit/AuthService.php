<?php

namespace App\Services\Connectivity\Sendit;

/**
 * Service to handle authentication and session token retrieval with Sendit API.
 */
class AuthService extends SenditHttpClient
{
    /**
     * Log in with the account's public/secret key pair and return the
     * response payload (contains `token` and `name`).
     *
     * @return array<string, mixed>
     */
    public function login(string $publicKey, string $secretKey): array
    {
        return $this->post('login', [
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
        ]);
    }
}
