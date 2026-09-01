<?php

namespace App\Services\Connectivity\Ameex;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * AmeexClient — the single entry point for all Ameex API interactions.
 *
 * Usage:
 *   $client = new AmeexClient(apiId: '2', apiKey: 'your-api-key');
 *   $client->addParcel([...]);
 *   $client->getTracking('MRK0824B2LP5041691');
 *
 * Credentials are passed to the constructor and sent as the C-Api-Id and
 * C-Api-Key headers. No config values are read from the application — this
 * class is fully self-contained.
 *
 * **Ameex never uses HTTP status codes to signal failure.** Every response
 * is HTTP 200, including rejected credentials and rejected requests, and
 * failure is reported across TWO independent layers of the body:
 *
 *   1. Authentication — `{"login": "error", ...}` when the API id/key pair
 *      is wrong. Note this is checked before routing, so an unknown URL
 *      returns exactly the same body as a real endpoint with bad
 *      credentials.
 *   2. The operation itself — `{"login": "success", "api": {"type":
 *      "error", "msg": "Veuillez choisir l'expéditeur"}}` when the request
 *      was authenticated but rejected.
 *
 * So neither `->throw()` nor a single body check is sufficient. unwrap()
 * checks both layers and raises a RequestException either way, matching
 * what throw() would raise for a real HTTP failure, so callers only need
 * one failure path. This mirrors what ColiixClient and ForceLogClient do
 * for the same reason.
 *
 * Requests are form-encoded (multipart/form-data in the vendor's own
 * collection); nested product rows use `products[0][id]` style keys.
 *
 * Docs: https://documenter.getpostman.com/view/10265205/2sA3rwLZD1
 */
class AmeexClient
{
    public const BASE_URL = 'https://api.ameex.app';

    protected string $apiId;

    protected string $apiKey;

    public function __construct(string $apiId, string $apiKey)
    {
        $this->apiId = $apiId;
        $this->apiKey = $apiKey;
    }

    /**
     * Base request with Ameex's credential headers applied.
     */
    protected function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders([
                'C-Api-Id' => $this->apiId,
                'C-Api-Key' => $this->apiKey,
            ])
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5);
    }

    /**
     * Create a parcel.
     *
     * `city` must be Ameex's own numeric city id — unlike ForceLog and
     * Coliix, a plain city name is not accepted here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> The unwrapped `api` payload.
     *
     * @throws RequestException
     */
    public function addParcel(array $data): array
    {
        return $this->unwrap(
            $this->http()->asForm()->post('/customer/Delivery/Parcels/Action/Type/Add', $data)
        );
    }

    /**
     * Fetch a single parcel's details by its parcel code.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getParcelInfo(string $parcelCode): array
    {
        return $this->unwrap(
            $this->http()->get('/customer/Delivery/Parcels/Info', ['ParcelCode' => $parcelCode])
        );
    }

    /**
     * Fetch a parcel's status history.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getTracking(string $parcelCode): array
    {
        return $this->unwrap(
            $this->http()->get('/customer/Delivery/Parcels/Tracking', ['ParcelCode' => $parcelCode])
        );
    }

    /**
     * Fetch tracking for up to 25 parcel codes in one call.
     *
     * @param  string[]  $parcelCodes
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getMassTracking(array $parcelCodes): array
    {
        return $this->unwrap(
            $this->http()->asForm()->post('/customer/Delivery/Parcels/MassTracking', [
                'codes' => implode(',', array_slice($parcelCodes, 0, 25)),
            ])
        );
    }

    /**
     * The status vocabulary Ameex reports on parcels.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getStatuses(): array
    {
        return $this->unwrap(
            $this->http()->get('/customer/Delivery/Parcels/Statuts')
        );
    }

    /**
     * List parcels. Ameex's list endpoint takes DataTables-style paging
     * (`start`/`length`) rather than a page number.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getParcels(array $filters = []): array
    {
        return $this->unwrap(
            $this->http()->asForm()->post('/customer/Delivery/Parcels/Json', [
                'start' => 0,
                'length' => 25,
                'all_data' => 1,
                ...$filters,
            ])
        );
    }

    /**
     * Whether this API id/key pair is accepted, for connect-time
     * verification.
     *
     * Ameex has no dedicated "check credentials" endpoint, so this
     * piggybacks on the parcel status list: it is authenticated, read-only,
     * needs no parameters, and has no side effects. Only the `login` layer
     * is read — an authenticated call that still reports an operation-level
     * error would prove the credentials are fine, which is all this asks.
     */
    public function verifyCredentials(): bool
    {
        try {
            $response = $this->http()->get('/customer/Delivery/Parcels/Statuts');
        } catch (RequestException) {
            return false;
        }

        $body = $response->json();

        return is_array($body) && ($body['login'] ?? null) === 'success';
    }

    /**
     * Pull the `api` payload out of an Ameex response, raising a
     * RequestException whenever either failure layer reports a problem.
     *
     * The response is deliberately NOT thrown on before this point, so the
     * body stays readable by callers that need to tell a business-level
     * rejection apart from a transport failure.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    protected function unwrap(Response $response): array
    {
        if ($response->failed()) {
            throw new RequestException($response);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RequestException($response);
        }

        // Layer 1: credentials. An unknown endpoint answers identically,
        // since Ameex authenticates before it routes.
        if (($body['login'] ?? null) !== 'success') {
            throw new RequestException($response);
        }

        $payload = $body['api'] ?? null;

        // Layer 2: the operation. `api` is null on an auth failure and
        // carries type/msg on a rejected request.
        if (! is_array($payload) || ($payload['type'] ?? null) === 'error') {
            throw new RequestException($response);
        }

        return $payload;
    }

    /**
     * Pull the human-readable rejection message out of a failed Ameex
     * response, for surfacing to the merchant.
     *
     * Ameex's messages are French and describe what to fix ("Veuillez
     * choisir l'expéditeur"), so they're worth showing rather than
     * replacing with a generic failure string.
     */
    public static function errorMessage(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        if (($body['login'] ?? null) !== 'success') {
            return 'Ameex rejected these API credentials.';
        }

        $message = $body['api']['msg'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
