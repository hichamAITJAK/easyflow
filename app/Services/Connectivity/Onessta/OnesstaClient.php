<?php

namespace App\Services\Connectivity\Onessta;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OnesstaClient — the single entry point for the Onessta partner API.
 *
 * Usage:
 *   $client = new OnesstaClient(partnerToken: '...', clientId: '...', apiKey: '...');
 *   $client->addParcel([...]);
 *   $client->getTracking('CD54598912');
 *
 * Three credentials go on every call:
 *
 *   - Authorization: Bearer — EasyFlow's own partner token, the same for
 *     every tenant (config('services.onessta.partner_token')).
 *   - Client-ID / API-Key — the Onessta client account the parcel belongs
 *     to, stored on each delivery account.
 *
 * Every body carries `success` and its own `code`, which does not always
 * match the HTTP status (a city list answers `"code": 201` with HTTP 200,
 * a validation failure answers 404). A bad API-Key / Client-ID pair comes
 * back as a bare HTTP 500 "Server Error" with no `success` at all. So
 * unwrap() trusts `success` alone and raises a RequestException for
 * anything else, matching what throw() would raise for a real HTTP
 * failure, so callers only need one failure path.
 *
 * Docs: https://api.onessta.com/docs/partners
 */
class OnesstaClient
{
    public const BASE_URL = 'https://api.onessta.com/api/v1/p';

    public function __construct(
        protected string $partnerToken,
        protected string $clientId,
        protected string $apiKey,
    ) {}

    /**
     * Base request with Onessta's three credential headers applied.
     */
    protected function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->partnerToken)
            ->withHeaders([
                'Client-ID' => $this->clientId,
                'API-Key' => $this->apiKey,
            ])
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(5);
    }

    /**
     * Create a parcel. `city` and `pickup_city` are {id, name} objects
     * carrying Onessta's own city ids — a name alone is not accepted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> The parcel object.
     *
     * @throws RequestException
     */
    public function addParcel(array $data): array
    {
        return $this->unwrap($this->http()->post('/parcels/add', $data))['parcel'] ?? [];
    }

    /**
     * A parcel's current status. Onessta answers this one with UPPERCASE
     * keys (ID, CODE, STATUS, NOTE).
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getParcel(string $code): array
    {
        return $this->unwrap($this->http()->post('/parcels/get_by_code', ['code' => $code]))['parcel'] ?? [];
    }

    /**
     * A parcel's status history, NEWEST first, each step carrying
     * status (code), name (French label), created_at and new_date.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    public function getTracking(string $code): array
    {
        return $this->unwrap($this->http()->post('/parcels/tracking', ['code' => $code]))['history'] ?? [];
    }

    /**
     * Change the receiver of a parcel still in progress (same city). The
     * token needs the parcels:update ability.
     *
     * @param  array{code: string, receiver: string, phone: string, address: string, note?: ?string}  $data
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function changeRecipient(array $data): array
    {
        return $this->unwrap($this->http()->post('/parcels/change_recipient', $data))['parcel'] ?? [];
    }

    /**
     * Every delivery city, each with this client's own delivered /
     * refused / returned fees.
     *
     * @return list<array{id: int, name: string, delivered_fees?: string, refused_fees?: string, retourned_fees?: string}>
     *
     * @throws RequestException
     */
    public function getCities(): array
    {
        return $this->unwrap($this->http()->get('/cities'))['cities'] ?? [];
    }

    /**
     * The cities Onessta can collect parcels from.
     *
     * @return list<array{id: int, name: string}>
     *
     * @throws RequestException
     */
    public function getPickupCities(): array
    {
        return $this->unwrap($this->http()->get('/pickup_cities'))['cities'] ?? [];
    }

    /**
     * Onessta's status vocabulary (code + French label). Not in the docs,
     * but served by the same API.
     *
     * @return list<array{code: string, label: string}>
     *
     * @throws RequestException
     */
    public function getStatuses(): array
    {
        return $this->unwrap($this->http()->get('/statuses'))['statuses'] ?? [];
    }

    /**
     * Products held in stock at Onessta.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    public function getProducts(): array
    {
        return $this->unwrap($this->http()->get('/products'))['products'] ?? [];
    }

    /**
     * Declare a product to Onessta's stock: name + ref + qty for a simple
     * product, or variant_names / variant_refs / variant_qtys for one with
     * variants.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function addProduct(array $data): array
    {
        return $this->unwrap($this->http()->post('/products/add', $data))['product'] ?? [];
    }

    /**
     * Add stock to one product reference.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function updateStock(string $ref, int $qty): array
    {
        return $this->unwrap($this->http()->post('/products/update_stock', ['ref' => $ref, 'qty' => $qty]))['inventory'] ?? [];
    }

    /**
     * Whether this token + Client-ID + API-Key are accepted, for
     * connect-time verification. Reads the city list: authenticated,
     * read-only and needs no parameters.
     */
    public function verifyCredentials(): bool
    {
        try {
            $this->getCities();

            return true;
        } catch (RequestException) {
            return false;
        }
    }

    /**
     * Pull `data` out of an Onessta response, raising a RequestException
     * whenever `success` isn't true.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    protected function unwrap(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body) || ($body['success'] ?? false) !== true) {
            throw new RequestException($response);
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * The message worth showing the merchant from a failed response: the
     * first field error when there is one ("The phone field is required."),
     * else Onessta's own message.
     */
    public static function errorMessage(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        foreach ((array) ($body['data'] ?? []) as $errors) {
            if (is_array($errors) && is_string($errors[0] ?? null)) {
                return $errors[0];
            }
        }

        $message = $body['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
