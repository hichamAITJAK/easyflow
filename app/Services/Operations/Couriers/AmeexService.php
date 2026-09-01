<?php

namespace App\Services\Operations\Couriers;

use App\DTOs\Ameex\AmeexNewParcelDTO;
use App\DTOs\Ameex\AmeexParcelDTO;
use App\Enums\OrderDeliveryStatus;
use App\Interfaces\DeliveryCourierInterface;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Services\Connectivity\Ameex\AmeexClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use RuntimeException;

class AmeexService implements DeliveryCourierInterface
{
    public function __construct(private readonly ?DeliveryAccount $account = null) {}

    /**
     * Create a parcel with Ameex for the given order, shipping to $city.
     *
     * Ameex's "city" field takes its own NUMERIC CITY ID, not a name —
     * unlike Coliix and ForceLog, which both accept a plain city name. So
     * the destination must be a synced city carrying an
     * external_courrier_id; there is no falling back to the order's own
     * customer_city string, because Ameex would reject it.
     *
     * @throws InvalidArgumentException if no city with an Ameex id is available.
     */
    public function addParcel(Order $order, ?DeleveryCourrierCity $city = null): AmeexParcelDTO
    {
        $cityId = $city?->external_courrier_id;

        if (! $cityId) {
            throw new InvalidArgumentException('Ameex requires a destination city with an Ameex city id.');
        }

        $payload = (new AmeexNewParcelDTO(
            receiver: (string) $order->customer_name,
            phone: (string) $order->customer_phone,
            city: (string) $cityId,
            address: (string) $order->customer_address,
            cod: (float) $order->total_amount,
            // The sender ("expéditeur"). Omitting it is what produces
            // Ameex's "Veuillez choisir l'expéditeur" rejection, and it
            // defaults to the account's own API id per the vendor's
            // collection ("Business ID : API ID").
            business: $this->businessId(),
            orderNum: (string) ($order->reference ?? $order->id),
            comment: $order->parcel_note,
            product: $order->parcel_nature,
            canOpen: (bool) $order->parcel_open,
            fragile: (bool) $order->parcel_fragile,
            replace: (bool) $order->parcel_replace,
        ))->toArray();

        return AmeexParcelDTO::fromArray(
            $this->client()->addParcel($payload)
        );
    }

    /**
     * Translate one of Ameex's own delivery status labels into our
     * OrderDeliveryStatus.
     *
     * Ameex's status vocabulary lives behind an authenticated endpoint
     * (Parcels/Statuts) that can't be read without live credentials, and
     * the vendor's Postman collection ships no saved example responses. The
     * mapping below therefore covers the standard Moroccan COD vocabulary
     * these carriers share — the same French labels OzonExpress and Coliix
     * report — in both accented and unaccented spellings, plus the
     * SCREAMING_SNAKE codes the API might return instead.
     *
     * An unrecognized status deliberately throws rather than silently
     * mapping to null: SyncDeliveryStatusesCommand catches
     * InvalidArgumentException per order and logs it as a warning, so a
     * label this list is missing surfaces as a visible, fixable gap instead
     * of a parcel that quietly stops updating. This is the same contract
     * every other courier service uses.
     *
     * Pre-pickup statuses map to null — the parcel isn't with the courier's
     * network yet, so there's nothing for us to reflect, and our own
     * AWAITING_PICKUP is set by createShipment() and must not be
     * overwritten.
     *
     * @throws InvalidArgumentException if $status isn't one this list knows.
     */
    public function mapDeliveryStatus(string $status): ?OrderDeliveryStatus
    {
        return match (mb_strtolower(trim($status))) {
            'nouveau colis', 'nouveau', 'new', 'new_parcel',
            'attente de ramassage', 'en attente de ramassage', 'waiting_pickup',
            'programme', 'programmé' => null,
            'ramasse', 'ramassé', 'expedie', 'expédié', 'recu', 'reçu',
            'en voyage', 'en cours', 'transit', 'in_transit', 'picked_up' => OrderDeliveryStatus::IN_TRANSIT,
            'mise en distribution', 'en distribution', 'out_for_delivery' => OrderDeliveryStatus::OUT_FOR_DELIVERY,
            'pas de reponse', 'pas de réponse', 'injoignable', 'numero errone',
            'numero erroné', 'hors-zone', 'hors zone', 'unreachable' => OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED,
            'reporte', 'reporté', 'postponed' => OrderDeliveryStatus::POSTPONED,
            'refuse', 'refusé', 'refused', 'rejected' => OrderDeliveryStatus::REFUSED,
            'livre', 'livré', 'delivered' => OrderDeliveryStatus::DELIVERED,
            'retourne', 'retourné', 'demande de retour', 'returned' => OrderDeliveryStatus::RETURNED_IN_TRANSIT,
            'annule', 'annulé', 'cancelled', 'canceled' => OrderDeliveryStatus::CANCELLED_AT_COURIER,
            default => throw new InvalidArgumentException("Unknown Ameex delivery status [{$status}]."),
        };
    }

    /**
     * Fetch this parcel's current raw status from Ameex, by its
     * courier_tracking_number (Ameex's "parcel code"). Feed the result
     * straight into mapDeliveryStatus().
     *
     * Reads the last entry of the tracking history, falling back to the
     * parcel's own current status when the history is shaped differently or
     * empty.
     *
     * Returns null when Ameex has nothing to report for this code, so one
     * unknown parcel is treated as "no change" rather than failing the
     * whole poll run — the same contract OzonExpressService and
     * ForceLogService use.
     *
     * @throws RequestException|ConnectionException on a real API failure.
     */
    public function getCurrentStatus(string $trackingNumber): ?string
    {
        try {
            $payload = $this->client()->getTracking($trackingNumber);
        } catch (RequestException $e) {
            // A genuine transport failure must propagate; Ameex's
            // 200-with-an-error-body "not found" must not.
            if ($e->response->failed()) {
                throw $e;
            }

            return null;
        }

        $history = $payload['tracking'] ?? $payload['history'] ?? $payload['data'] ?? [];

        if (is_array($history) && $history !== []) {
            $latest = end($history);

            if (is_array($latest)) {
                $status = $latest['statut'] ?? $latest['status'] ?? $latest['statut_name'] ?? $latest['status_name'] ?? null;

                if (is_string($status) && $status !== '') {
                    return $status;
                }
            }
        }

        // No usable history — fall back to whatever current status the
        // payload itself carries.
        $status = $payload['statut'] ?? $payload['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Ameex publishes no city-list endpoint, and its API authenticates
     * before it routes — an unknown path answers identically to a valid one
     * with bad credentials — so no endpoint could be discovered for this.
     * Its coverage list only exists as a static CSV export, so the import
     * lives in SyncCourierCitiesCommand (same as Coliix) rather than here:
     * it reads a file, needs no account credentials, and is courier-wide.
     *
     * Run `php artisan couriers:sync-cities --courier=Ameex` instead.
     */
    public function syncCities(): void
    {
        throw new RuntimeException(
            'Ameex cities are imported from a CSV export — run `php artisan couriers:sync-cities --courier=Ameex`.'
        );
    }

    /**
     * Look up a single parcel by its Ameex parcel code.
     */
    public function getParcel(string $trackingNumber): AmeexParcelDTO
    {
        return AmeexParcelDTO::fromArray(
            $this->client()->getParcelInfo($trackingNumber)
        );
    }

    /**
     * List this account's parcels.
     *
     * @param  array<string, mixed>  $filters
     * @return AmeexParcelDTO[]
     */
    public function listParcels(array $filters = []): array
    {
        return AmeexParcelDTO::fromList(
            $this->client()->getParcels($filters)
        );
    }

    /**
     * The sender ("expéditeur") id sent with every parcel.
     *
     * Ameex's own collection labels this "Business ID : API ID", so it
     * defaults to the account's API id, with an explicit override for
     * merchants whose sender id differs from it.
     */
    private function businessId(): ?string
    {
        $credentials = $this->credentials();

        $business = $credentials['business_id'] ?? $credentials['api_id'] ?? null;

        return $business === null || $business === '' ? null : (string) $business;
    }

    /**
     * Build an authenticated Ameex client from this account's stored
     * credentials.
     */
    private function client(): AmeexClient
    {
        $credentials = $this->credentials();

        return new AmeexClient(
            $credentials['api_id'] ?? '',
            $credentials['api_key'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        return json_decode((string) $this->account?->api_credentials, true) ?? [];
    }
}
