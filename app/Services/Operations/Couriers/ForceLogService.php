<?php

namespace App\Services\Operations\Couriers;

use App\DTOs\ForceLog\ForceLogCityDTO;
use App\DTOs\ForceLog\ForceLogNewParcelDTO;
use App\DTOs\ForceLog\ForceLogParcelDTO;
use App\Enums\Courier;
use App\Enums\OrderDeliveryStatus;
use App\Interfaces\DeliveryCourierInterface;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\Order;
use App\Services\Connectivity\ForceLog\ForceLogClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class ForceLogService implements DeliveryCourierInterface
{
    public function __construct(private readonly ?DeliveryAccount $account = null) {}

    /**
     * Create a parcel with ForceLog for the given order, shipping to $city.
     *
     * ForceLog's AddParcel "CITY" field accepts either the city's code or
     * its plain name, so the synced city's external_courrier_id (which holds
     * ForceLog's numeric id) is deliberately NOT used here — the name is,
     * falling back to the order's own customer_city when no city was
     * resolved. This is the same shape ColiixService uses.
     *
     * ForceLog's add-parcel response carries no delivery fee (and there is
     * no per-parcel endpoint that returns one at creation time), so the fee
     * is resolved separately from the account's own city list — see
     * resolveDeliveryCost().
     *
     * @throws InvalidArgumentException if no city is available.
     */
    public function addParcel(Order $order, ?DeleveryCourrierCity $city = null): ForceLogParcelDTO
    {
        $cityName = $city->name ?? $order->customer_city;

        if (! $cityName) {
            throw new InvalidArgumentException('ForceLog requires a destination city.');
        }

        $payload = (new ForceLogNewParcelDTO(
            // ForceLog caps ORDER_NUM at 20 chars and uses it as the
            // merchant-facing reconciliation key, so our own order reference
            // is what belongs here — not the numeric id.
            orderNum: (string) ($order->reference ?? $order->id),
            receiver: (string) $order->customer_name,
            phone: (string) $order->customer_phone,
            city: $cityName,
            address: (string) $order->customer_address,
            cod: (float) $order->total_amount,
            comment: $order->parcel_note,
            productNature: $order->parcel_nature,
            canOpen: (bool) $order->parcel_open,
            fragile: (bool) $order->parcel_fragile,
        ))->toArray();

        $parcel = ForceLogParcelDTO::fromArray(
            $this->client()->addParcel($payload)
        );

        return $parcel->withDeliveryCost($this->resolveDeliveryCost($city));
    }

    /**
     * Look up what ForceLog will charge this account to deliver to $city.
     *
     * ForceLog returns no fee on the add-parcel response, and exposes no
     * per-parcel pricing endpoint — the only source is /customer/Cities,
     * which quotes a fee per city. Those fees are ACCOUNT-SCOPED: each
     * customer negotiates their own rates, so this must run with the
     * account's own API key, never the developer key that
     * SyncCourierCitiesCommand uses (that one only exists to harvest the
     * courier-wide names and ids, which don't vary).
     *
     * Cities are matched by ForceLog's own numeric id, held in
     * external_courrier_id — not by name, which would be fragile against
     * accents, casing, and the duplicate place names Morocco has plenty of.
     *
     * Returns null rather than throwing on any failure: the parcel has
     * already been created at this point, so a fee lookup that fails must
     * not take the shipment down with it. A null delivery_cost is the same
     * thing Coliix records for every parcel, and it is recoverable later
     * from the parcel's own GetParcel DELIVERY_FEES once it exists.
     */
    private function resolveDeliveryCost(?DeleveryCourrierCity $city): ?float
    {
        $externalId = $city?->external_courrier_id;

        if ($externalId === null || $externalId === '') {
            return null;
        }

        try {
            $cities = $this->client()->getCities();
        } catch (RequestException|ConnectionException $e) {
            Log::warning('ForceLog delivery fee lookup failed', [
                'delivery_account_id' => $this->account?->id,
                'city_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $row = $cities[(string) $externalId] ?? null;

        if (! is_array($row)) {
            return null;
        }

        $fees = ForceLogCityDTO::fromArray((string) $externalId, $row);

        // ForceLog quotes two rates per city: the standard one, and a lower
        // one for parcels that never leave the pickup city. Which applies
        // depends on where this account collects from.
        return $this->isSameCityAsPickup($externalId)
            ? ($fees->deliveryFeesSameCity ?? $fees->deliveryFees)
            : $fees->deliveryFees;
    }

    /**
     * Whether a destination city is the same one this account's parcels are
     * collected from, which is what makes ForceLog's same-city rate apply.
     */
    private function isSameCityAsPickup(string $destinationExternalId): bool
    {
        $collectExternalId = $this->account?->collectCity?->external_courrier_id;

        return $collectExternalId !== null
            && (string) $collectExternalId === $destinationExternalId;
    }

    /**
     * Translate one of ForceLog's delivery statuses into our
     * OrderDeliveryStatus.
     *
     * ForceLog reports a status two ways: a technical STATUS_CODE
     * (`DELIVERED`) on GetParcels and GetTracking, and a French display
     * STATUS ("Livré") on GetParcel and GetParcels. Both are accepted here
     * so callers can pass whichever the endpoint they used gave them —
     * getCurrentStatus() below returns the code, since it is the stable one.
     *
     * NEW_PARCEL and WAITING_PICKUP are pre-pickup: the parcel isn't with
     * the courier's network yet, so there's nothing here for us to reflect.
     * They map to null rather than a status, for the same reason
     * Sendit/OzonExpress/Coliix's own pre-pickup statuses do — our own
     * AWAITING_PICKUP is set by createShipment(), and a courier status
     * update must never overwrite it.
     *
     * ForceLog has no status corresponding to OrderDeliveryStatus::CHANGED,
     * ::READY_FOR_PICKUP, ::RETURN_RECEIVED, ::POSTPONED,
     * ::DELIVERY_ATTEMPT_FAILED, or ::REFUSED — its documented vocabulary is
     * only the six codes below, so no code here produces those. In
     * particular a refused-at-the-door parcel surfaces as RETURNED, not as a
     * distinct refusal.
     *
     * @throws InvalidArgumentException if $status isn't one ForceLog reports.
     */
    public function mapDeliveryStatus(string $status): ?OrderDeliveryStatus
    {
        return match (trim($status)) {
            'NEW_PARCEL', 'Nouveau',
            'WAITING_PICKUP', 'En attente de ramassage' => null,
            'IN_PROGRESS', 'En cours' => OrderDeliveryStatus::IN_TRANSIT,
            'DELIVERED', 'Livré' => OrderDeliveryStatus::DELIVERED,
            'RETURNED', 'Retourné' => OrderDeliveryStatus::RETURNED_IN_TRANSIT,
            'CANCELLED', 'Annulé' => OrderDeliveryStatus::CANCELLED_AT_COURIER,
            default => throw new InvalidArgumentException("Unknown ForceLog delivery status [{$status}]."),
        };
    }

    /**
     * Fetch this parcel's current status straight from ForceLog, by its
     * courier_tracking_number.
     *
     * Reads the last entry of GetTracking's history rather than GetParcel's
     * STATUS field, because the history carries STATUS_CODE (the stable
     * technical code) while GetParcel returns only the French display label.
     * Feed the result straight into mapDeliveryStatus().
     *
     * Returns null when the parcel has no history yet, and — like
     * OzonExpressService — when ForceLog reports a business-level "not
     * found" rather than a real API failure. A tracking number the courier
     * has never heard of is a data problem for one order, not a reason to
     * fail the whole poll run.
     *
     * @throws RequestException|ConnectionException on a real API failure.
     */
    public function getCurrentStatus(string $trackingNumber): ?string
    {
        try {
            $response = $this->client()->getTracking($trackingNumber);
        } catch (RequestException $e) {
            // "Parcel Not Found" must not propagate, but ForceLog reports it
            // as a real HTTP 400 (not the 200-with-RESULT-ERROR the docs
            // describe), so the status code alone can't tell the two apart —
            // the error MESSAGE in the body is what distinguishes a tracking
            // number the courier has never heard of from a genuine outage.
            if (! $this->isNotFound($e)) {
                throw $e;
            }

            return null;
        }

        $history = $response['HISTORY'] ?? [];

        if (! is_array($history) || $history === []) {
            return null;
        }

        $latest = end($history);

        return $latest['STATUS_CODE'] ?? $latest['STATUS_NAME'] ?? null;
    }

    /**
     * Whether a failed ForceLog call was a business-level "this parcel
     * doesn't exist" rather than a real API failure.
     *
     * ForceLog answers an unknown tracking code with an HTTP 400 whose body
     * still carries a normal error envelope ("Parcel code Not Found" /
     * "Parcel Not Found"), so the status code can't distinguish it from a
     * genuine client-side error — the message is the only signal. A 5xx or
     * a body that isn't recognisably a not-found is always treated as a
     * real failure, so an outage is never silently swallowed as "no change".
     */
    private function isNotFound(RequestException $e): bool
    {
        if ($e->response->serverError()) {
            return false;
        }

        $body = $e->response->json();

        if (! is_array($body)) {
            return false;
        }

        // The envelope key varies per operation, so the message is searched
        // for wherever it landed rather than under one fixed key.
        $message = $body['MESSAGE']
            ?? $body['GET-TRACKING']['MESSAGE']
            ?? $body['GET-PARCEL']['MESSAGE']
            ?? '';

        return stripos((string) $message, 'not found') !== false;
    }

    /**
     * Fetch ForceLog's covered cities and upsert them as this courier's
     * cities.
     *
     * This runs against THIS account's own API key. The courier-wide sync
     * that populates the city dropdown for every tenant runs from
     * SyncCourierCitiesCommand instead, using the developer key in
     * config('services.forcelog') — the list is identical either way, since
     * ForceLog's coverage doesn't vary per customer.
     *
     * @throws RuntimeException if the courier isn't seeded.
     */
    public function syncCities(): void
    {
        $courier = DeliveryCourrier::where('slug', Courier::FORCELOG->value)->first()
            ?? throw new RuntimeException('ForceLog courier is not seeded yet');

        foreach (ForceLogCityDTO::fromMap($this->client()->getCities()) as $city) {
            if ($city->name === '') {
                continue;
            }

            DeleveryCourrierCity::updateOrCreate(
                [
                    'courrier_id' => $courier->id,
                    'external_courrier_id' => $city->id,
                ],
                [
                    'name' => $city->name,
                ],
            );
        }
    }

    /**
     * List every parcel ForceLog holds for this account, one page at a time.
     *
     * @param  array<string, mixed>  $filters
     * @return ForceLogParcelDTO[]
     */
    public function listParcels(array $filters = []): array
    {
        return ForceLogParcelDTO::fromList(
            $this->client()->getParcels($filters)
        );
    }

    /**
     * Look up a single parcel by its tracking code.
     */
    public function getParcel(string $trackingNumber): ForceLogParcelDTO
    {
        return ForceLogParcelDTO::fromArray(
            $this->client()->getParcel($trackingNumber)
        );
    }

    /**
     * Build an authenticated ForceLog client from this account's stored
     * credentials.
     */
    private function client(): ForceLogClient
    {
        $credentials = json_decode((string) $this->account->api_credentials, true) ?? [];

        return new ForceLogClient($credentials['api_key'] ?? '');
    }
}
