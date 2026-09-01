<?php

namespace App\Services\Connectivity\OzonExpress;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class OzonExpressClient
{
    protected string $baseUrl;

    protected string $ozonId;

    protected string $apiKey;

    public function __construct(string $ozonId, string $apiKey)
    {
        $this->baseUrl = 'https://api.ozonexpress.ma';
        $this->ozonId = $ozonId;
        $this->apiKey = $apiKey;
    }

    /**
     * Create a shipment in OzonExpress. Returns the full response, still
     * wrapped in its ADD-PARCEL envelope — unwrapping is OzonExpressParcelDTO's
     * job (see OzonExpressParcelDTO::fromArray()), not this client's.
     *
     * OzonExpress reports API-level failures (e.g. an invalid city) with an
     * HTTP 200 and ADD-PARCEL.RESULT !== "SUCCESS" rather than an HTTP error
     * status, so throw() alone wouldn't catch them — a RequestException is
     * raised manually in that case, matching what throw() would raise for
     * an HTTP-level failure, so callers only need one failure path.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function createParcel(array $data): array
    {
        $url = "{$this->baseUrl}/customers/{$this->ozonId}/{$this->apiKey}/add-parcel";

        $response = Http::asForm()->post($url, $data)->throw();

        if (($response->json('ADD-PARCEL.RESULT') ?? 'FAILED') !== 'SUCCESS') {
            throw new RequestException($response);
        }

        return $response->json();
    }

    /**
     * Check whether this account's ozon_id/api_key pair is valid, for
     * connect-time credential verification. There's no dedicated
     * "check credentials" endpoint, so this piggybacks on /tracking with a
     * fixed, almost-certainly-nonexistent tracking number — the tracking
     * number itself is expected to be rejected either way, but the
     * response's CHECK_API.RESULT reports whether the ozon_id/api_key pair
     * itself was accepted before OzonExpress even looked at the tracking
     * number. Deliberately does not call getTracking(): that method
     * throws on TRACKING.RESULT !== "SUCCESS", which a fake tracking
     * number always triggers regardless of whether the credentials are
     * valid — this method needs CHECK_API.RESULT read independently of
     * that, not masked by it.
     */
    public function verifyCredentials(): bool
    {
        $url = "{$this->baseUrl}/customers/{$this->ozonId}/{$this->apiKey}/tracking";

        try {
            $response = Http::asForm()->post($url, [
                'tracking-number' => 'NOSHEET-CREDENTIALS-CHECK',
            ])->throw();
        } catch (RequestException) {
            return false;
        }

        return $response->json('CHECK_API.RESULT') === 'SUCCESS';
    }

    /**
     * List the cities OzonExpress covers. Public endpoint, no credentials required.
     *
     * @return array<string, mixed>
     */
    /**
     * @return array{CITIES?: array<int, array<string, mixed>>}|array<int, array<string, mixed>>
     */
    public function getCities(): array
    {
        return Http::get("{$this->baseUrl}/cities")->throw()->json();
    }

    /**
     * Retrieve parcel information. Returns the full response, still wrapped
     * in its PARCEL-INFO envelope (PARCEL-INFO.INFOS) — unwrapping is left
     * to the caller, same as createParcel()'s ADD-PARCEL envelope.
     *
     * Like the other endpoints, OzonExpress reports API-level failures with
     * an HTTP 200 rather than an HTTP error status — but for parcel-info
     * specifically, at least one real error response nests its RESULT
     * under ADD-PARCEL rather than PARCEL-INFO (a bad/empty tracking number
     * request), so both envelope keys are checked here; a RequestException
     * is raised manually for either, matching what throw() would raise for
     * an HTTP-level failure, so callers only need one failure path.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getParcelInfo(string $trackingNumber): array
    {
        $url = "{$this->baseUrl}/customers/{$this->ozonId}/{$this->apiKey}/parcel-info";

        $response = Http::asForm()->post($url, [
            'tracking-number' => $trackingNumber,
        ])->throw();

        $result = $response->json('PARCEL-INFO.RESULT') ?? $response->json('ADD-PARCEL.RESULT') ?? 'FAILED';

        if ($result !== 'SUCCESS') {
            throw new RequestException($response);
        }

        return $response->json();
    }

    /**
     * Get tracking status history. Returns the full response, still wrapped
     * in its TRACKING envelope (TRACKING.HISTORY / TRACKING.LAST_TRACKING) —
     * unwrapping is left to the caller, same as createParcel()'s ADD-PARCEL
     * envelope.
     *
     * Like add-parcel, OzonExpress reports API-level failures (e.g. an
     * unknown tracking number) with an HTTP 200 and TRACKING.RESULT !==
     * "SUCCESS" rather than an HTTP error status, so throw() alone
     * wouldn't catch them — a RequestException is raised manually in that
     * case, matching what throw() would raise for an HTTP-level failure,
     * so callers only need one failure path.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function getTracking(string $trackingNumber): array
    {
        $url = "{$this->baseUrl}/customers/{$this->ozonId}/{$this->apiKey}/tracking";

        $response = Http::asForm()->post($url, [
            'tracking-number' => $trackingNumber,
        ])->throw();

        if (($response->json('TRACKING.RESULT') ?? 'FAILED') !== 'SUCCESS') {
            throw new RequestException($response);
        }

        return $response->json();
    }

    /**
     * Create a new delivery note (BL).
     *
     * @return array<string, mixed>
     */
    public function createDeliveryNote(): array
    {
        $url = "{$this->baseUrl}/customers/{$this->ozonId}/{$this->apiKey}/add-delivery-note";

        return Http::asForm()->post($url)->throw()->json();
    }

    /**
     * Add parcels to a delivery note (BL).
     *
     * @param  array<int, string>  $parcelCodes
     * @return array<string, mixed>
     */
    public function addParcelsToDeliveryNote(string $blRef, array $parcelCodes): array
    {
        $url = "{$this->baseUrl}/customers/{$this->ozonId}/{$this->apiKey}/add-parcel-to-delivery-note";

        $formData = ['Ref' => $blRef];
        foreach ($parcelCodes as $index => $code) {
            $formData["Codes[{$index}]"] = $code;
        }

        return Http::asForm()->post($url, $formData)->throw()->json();
    }

    /**
     * Finalize and save a delivery note (BL).
     *
     * @return array<string, mixed>
     */
    public function saveDeliveryNote(string $blRef): array
    {
        $url = "{$this->baseUrl}/customers/{$this->ozonId}/{$this->apiKey}/save-delivery-note";

        return Http::asForm()->post($url, ['Ref' => $blRef])->throw()->json();
    }

    /**
     * Get PDF Standard URL for a delivery note.
     */
    public function getPdfStandardUrl(string $blRef): string
    {
        return "https://client.ozoneexpress.ma/pdf-delivery-note?dn-ref={$blRef}";
    }

    /**
     * Get PDF A4 Labels URL for a delivery note.
     */
    public function getPdfLabelsA4Url(string $blRef): string
    {
        return "https://client.ozoneexpress.ma/pdf-delivery-note-tickets?dn-ref={$blRef}";
    }

    /**
     * Get PDF 10x10cm Labels URL for a delivery note.
     */
    public function getPdfLabels10x10Url(string $blRef): string
    {
        return "https://client.ozoneexpress.ma/pdf-delivery-note-tickets-4-4?dn-ref={$blRef}";
    }
}
