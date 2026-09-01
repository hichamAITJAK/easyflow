<?php

namespace App\Services\Connectivity\Coliix;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class ColiixClient
{
    protected string $apiEndpoint;

    protected string $token;

    public function __construct(string $token)
    {
        $this->apiEndpoint = 'https://my.coliix.com/casa/seller/api-parcels';
        $this->token = $token;
    }

    /**
     * Create a parcel ("colis"). Coliix reports API-level failures (e.g. a
     * disabled account) with an HTTP 200 and status !== 200 rather than an
     * HTTP error status, so throw() alone wouldn't catch them — a
     * RequestException is raised manually in that case, matching what
     * throw() would raise for an HTTP-level failure, so callers only need
     * one failure path.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function addParcel(array $data): array
    {
        $response = Http::asForm()->post($this->apiEndpoint, [
            ...$data,
            'action' => 'add',
            'token' => $this->token,
        ])->throw();

        if ((int) ($response->json('status') ?? 0) !== 200) {
            throw new RequestException($response);
        }

        return $response->json();
    }

    /**
     * Track a parcel by its tracking code ("code d'envoi").
     *
     * Unlike addParcel()'s documented 200/204 status codes, a real track
     * response reports success as a boolean "status": true (with "msg" as
     * the history array) rather than an HTTP-style code — so success here
     * is checked against `true` specifically, not the 200 addParcel() uses.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function track(string $tracking): array
    {
        $response = Http::asForm()->post($this->apiEndpoint, [
            'action' => 'track',
            'token' => $this->token,
            'tracking' => $tracking,
        ])->throw();

        if ($response->json('status') !== true) {
            throw new RequestException($response);
        }

        return $response->json();
    }

    /**
     * Check whether this account's token (api key) is valid, for connect-time
     * credential verification. Coliix has no dedicated "check credentials"
     * endpoint, so this piggybacks on "track" with a fixed,
     * almost-certainly-nonexistent tracking number — a disabled/invalid
     * token reports "status": 204 ("Le compte est désactivé") regardless of
     * the tracking number, while a valid token still reports "status": true
     * (the same boolean track() itself checks) even for an unknown tracking
     * number — Coliix just returns an empty/absent "msg" history for those.
     */
    public function verifyCredentials(): bool
    {
        try {
            $response = Http::asForm()->post($this->apiEndpoint, [
                'action' => 'track',
                'token' => $this->token,
                'tracking' => 'NOSHEET-CREDENTIALS-CHECK',
            ])->throw();
        } catch (RequestException) {
            return false;
        }

        return $response->json('status') !== 204;
    }
}
