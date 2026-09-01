<?php

namespace App\Services\Connectivity\ForceLog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * ForceLogClient — the single entry point for all ForceLog API interactions.
 *
 * Usage:
 *   $client = new ForceLogClient(apiKey: 'your-api-key');
 *   $client->addParcel([...]);
 *   $client->getTracking('F-XXXXXXX');
 *
 * Credentials are passed to the constructor. No config values are read from
 * the application — this class is fully self-contained.
 *
 * Three things about this API shape every method here has to work around,
 * all of them verified against the live API rather than taken from the
 * published docs, which differ on each point:
 *
 *  1. **Failure is signalled inconsistently.** Some errors come back as
 *     HTTP 200 with `RESULT: "ERROR"` in the body; others (an unknown
 *     tracking code) use a real HTTP 400 carrying the same envelope. So
 *     neither `->throw()` alone nor a body check alone is sufficient —
 *     unwrap() handles both and raises a single RequestException, so
 *     callers only need one failure path. This mirrors what ColiixClient
 *     does for the same reason.
 *  2. **Every response carries a sibling `AUTH` envelope** confirming the
 *     API key, alongside the operation's own envelope. It is stripped
 *     wherever it would otherwise be mistaken for data.
 *  3. **The success envelope key varies per operation** (`ADD-PARCEL`,
 *     `GET-PARCELS`, `GET-TRACKING`, `GET-PARCEL`, ...), and the city list
 *     nests its id-keyed map under `Cities` instead. unwrap() and
 *     getCities() are the only places that know which is which.
 *
 * Docs: https://api.forcelog.ma (see storage/postman for the full collection)
 */
class ForceLogClient
{
    public const BASE_URL = 'https://api.forcelog.ma';

    protected string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Base request with ForceLog's custom auth header applied.
     */
    protected function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5);
    }

    /**
     * Create a parcel.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> The unwrapped ADD-PARCEL payload.
     *
     * @throws RequestException
     */
    public function addParcel(array $data): array
    {
        return $this->unwrap(
            $this->http()->post('/customer/Parcels/AddParcel', $data),
            'ADD-PARCEL',
        );
    }

    /**
     * Fetch a single parcel by its tracking code.
     *
     * Note the capitalised `Code` query parameter. The published docs show
     * this endpoint returning RESULT at the top level with no wrapper, but
     * the live API wraps it in GET-PARCEL like every other operation —
     * unwrap() falls back to the top level for the documented shape.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getParcel(string $trackingNumber): array
    {
        return $this->unwrap(
            $this->http()->get('/customer/Parcels/GetParcel', ['Code' => $trackingNumber]),
            'GET-PARCEL',
        );
    }

    /**
     * Fetch a parcel's full status history, oldest entry first.
     *
     * @return array<string, mixed> The unwrapped GET-TRACKING payload.
     *
     * @throws RequestException
     */
    public function getTracking(string $trackingNumber): array
    {
        return $this->unwrap(
            $this->http()->get('/customer/Parcels/GetTracking', ['Code' => $trackingNumber]),
            'GET-TRACKING',
        );
    }

    /**
     * List parcels, filtered and paginated.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> The unwrapped GET-PARCELS payload.
     *
     * @throws RequestException
     */
    public function getParcels(array $query = []): array
    {
        return $this->unwrap(
            $this->http()->get('/customer/Parcels/GetParcels', $query),
            'GET-PARCELS',
        );
    }

    /**
     * Delete a parcel. ForceLog only allows this while the parcel is still
     * in NEW_PARCEL status, and it is a POST rather than a DELETE.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function deleteParcel(string $trackingNumber): array
    {
        return $this->unwrap(
            $this->http()->post('/customer/Parcels/DeleteParcel', ['CODE' => $trackingNumber]),
            'DELETE-PARCEL',
        );
    }

    /**
     * Every city ForceLog serves, as a map keyed by numeric city id:
     *
     *   {"34": {"CODE": "CAS", "NAME": "Casablanca", "D_FEES": "30", ...}}
     *
     * The published docs show that map as the whole response body, but the
     * live API actually nests it under a "Cities" key alongside an "AUTH"
     * envelope:
     *
     *   {"AUTH": {"RESULT": "SUCCESS", ...}, "Cities": {"34": {...}}}
     *
     * Both shapes are accepted here, so this keeps working whichever one a
     * given deployment returns. There is no RESULT field on the city map
     * itself, so this doesn't go through unwrap().
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws RequestException
     */
    public function getCities(): array
    {
        $body = $this->http()->get('/customer/Cities')->throw()->json() ?? [];

        if (! is_array($body)) {
            return [];
        }

        // Accept either the documented bare map or the live "Cities"
        // envelope. AUTH is stripped either way — it is an authentication
        // receipt, not a city.
        $cities = $body['Cities'] ?? $body['CITIES'] ?? $body;

        if (! is_array($cities)) {
            return [];
        }

        unset($cities['AUTH']);

        return $cities;
    }

    /**
     * Create a pickup request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function createPickupRequest(array $data): array
    {
        return $this->unwrap(
            $this->http()->post('/customer/Pickups/CreateRequest', $data),
            'ADD-PICKUP',
        );
    }

    /**
     * Whether this API key is accepted, for connect-time verification.
     *
     * ForceLog has no dedicated "check credentials" endpoint. /customer/Cities
     * is used because it is authenticated, read-only, needs no parameters, and
     * has no side effects — an invalid key fails there just as it would
     * anywhere else. /health is deliberately NOT used: it is unauthenticated,
     * so it would return success for any key at all.
     */
    public function verifyCredentials(): bool
    {
        try {
            $response = $this->http()->get('/customer/Cities')->throw();
        } catch (RequestException) {
            return false;
        }

        $body = $response->json();

        if (! is_array($body) || $body === []) {
            return false;
        }

        // A valid key is confirmed by the AUTH envelope every response
        // carries. A rejected one may still come back HTTP 200, so the
        // body is what decides — an error at either level is a no.
        return ($body['RESULT'] ?? null) !== 'ERROR'
            && ($body['AUTH']['RESULT'] ?? null) !== 'ERROR';
    }

    /**
     * Pull the payload out of ForceLog's response envelope, raising a
     * RequestException whenever the operation didn't succeed.
     *
     * ForceLog is inconsistent about how it signals failure: some errors
     * come back as HTTP 200 with RESULT: "ERROR" in the body, while others
     * (an unknown tracking code, for one) use a real HTTP 400 that still
     * carries the same error envelope. Both are funnelled into a single
     * RequestException here so callers only need one failure path — and
     * crucially the response is NOT thrown on before this point, so a 4xx
     * body is still readable by callers that need to tell a business-level
     * "not found" apart from a transport failure (see
     * ForceLogService::getCurrentStatus).
     *
     * Every response also carries a sibling AUTH envelope confirming the
     * API key; it is ignored here, since a rejected key never reaches this
     * far.
     *
     * @param  string|null  $envelope  The wrapper key, or null when RESULT sits at the top level.
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    protected function unwrap(Response $response, ?string $envelope): array
    {
        $body = $response->json() ?? [];

        if (! is_array($body)) {
            throw new RequestException($response);
        }

        // Prefer the documented envelope, falling back to the top level for
        // the shape the published docs describe.
        $payload = $envelope !== null && is_array($body[$envelope] ?? null)
            ? $body[$envelope]
            : $body;

        // A payload with no RESULT at all didn't have the shape this
        // operation returns — treat it as a failure rather than silently
        // handing back something unusable.
        if (($payload['RESULT'] ?? null) !== 'SUCCESS') {
            throw new RequestException($response);
        }

        return $payload;
    }
}
