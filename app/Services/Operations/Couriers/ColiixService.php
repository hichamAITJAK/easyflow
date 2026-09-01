<?php

namespace App\Services\Operations\Couriers;

use App\DTOs\Coliix\ColiixNewParcelDTO;
use App\DTOs\Coliix\ColiixParcelDTO;
use App\Enums\OrderDeliveryStatus;
use App\Interfaces\DeliveryCourierInterface;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Services\Connectivity\Coliix\ColiixClient;
use InvalidArgumentException;

class ColiixService implements DeliveryCourierInterface
{
    public function __construct(private readonly ?DeliveryAccount $account = null) {}

    /**
     * Create a parcel with Coliix for the given order, shipping to $city.
     * Coliix's "ville" field takes a plain city name, not an id, so
     * $city->name is used (falling back to the order's own customer_city
     * when no city was resolved).
     *
     * @throws InvalidArgumentException if no city name is available.
     */
    public function addParcel(Order $order, ?DeleveryCourrierCity $city = null): ColiixParcelDTO
    {
        $ville = $city->name ?? $order->customer_city;

        if (! $ville) {
            throw new InvalidArgumentException('Coliix requires a destination city.');
        }

        $payload = (new ColiixNewParcelDTO(
            name: $order->customer_name,
            phone: $order->customer_phone,
            marchandise: $order->parcel_nature ?? '',
            marchandiseQty: 1,
            ville: $ville,
            adresse: $order->customer_address,
            price: (float) $order->total_amount,
            note: $order->parcel_note,
        ))->toArray();

        return ColiixParcelDTO::fromArray(
            $this->client()->addParcel($payload)
        );
    }

    /**
     * Translate one of Coliix's own delivery status labels — the french
     * "status" field seen both in a track() response's history entries and
     * in Coliix's own status filter dropdown — into our OrderDeliveryStatus.
     *
     * Real track() responses carry a trailing space on every status label
     * (e.g. "Nouveau Colis ", "Livré ") — trim() first so entries match
     * regardless of whether that's present.
     *
     * "Nouveau Colis"/"Attente De Ramassage"/"Programmé" are pre-pickup —
     * the parcel isn't with the courier's network yet, so there's nothing
     * here for us to reflect; they map to null rather than a status, for
     * the same reason Sendit/OzonExpress's own pre-pickup statuses do.
     *
     * "Client intéressé"/"Client pas intéressé"/"Client pas commandé"/
     * "Confirmer par le livreur"/"En cours"/"Boite Vocal"/"Relancé nouveau
     * client" are informational notes logged mid-delivery-attempt rather
     * than a status transition of their own, so they also map to null —
     * same reasoning as OzonExpress's "client intéressé".
     *
     * Coliix has no status corresponding to OrderDeliveryStatus::CHANGED,
     * ::READY_FOR_PICKUP, or ::RETURN_RECEIVED — this courier just doesn't
     * report those concepts, so no code below produces them.
     *
     * @throws InvalidArgumentException if $status isn't one Coliix reports.
     */
    public function mapDeliveryStatus(string $status): ?OrderDeliveryStatus
    {
        return match (trim($status)) {
            'Nouveau Colis', 'Attente De Ramassage', 'Programmé',
            'Client intéressé', 'Client pas intéressé', 'CLIENT PAS COMMENDE',
            'Confirmer par le livreur', 'En cours', 'Boite Vocal',
            'Relancé nouveau client' => null,
            'Ramassé', 'Expédié', 'Reçu', 'En Voyage' => OrderDeliveryStatus::IN_TRANSIT,
            'Mise en distribution' => OrderDeliveryStatus::OUT_FOR_DELIVERY,
            'Pas de réponse', 'Deuxième Appel Pas Réponse', 'Troisième Appel Pas Réponse',
            'Numero_Erroné', 'Injoignable', 'Hors-zone', 'Attende de relancer' => OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED,
            'Reporté' => OrderDeliveryStatus::POSTPONED,
            'Refusé' => OrderDeliveryStatus::REFUSED,
            'Livré' => OrderDeliveryStatus::DELIVERED,
            'Retourné', 'Demande de Retour', 'En retour par AMANA' => OrderDeliveryStatus::RETURNED_IN_TRANSIT,
            'Annulé', 'Annulé par Vendeur' => OrderDeliveryStatus::CANCELLED_AT_COURIER,
            default => throw new InvalidArgumentException("Unknown Coliix delivery status [{$status}]."),
        };
    }

    /**
     * Coliix's API exposes no city-list endpoint — nothing to sync yet.
     */
    public function syncCities(): void
    {
        throw new \RuntimeException('Coliix city sync is not implemented yet.');
    }

    /**
     * Look up a parcel's tracking history by its tracking code.
     *
     * @return array<string, mixed>
     */
    public function track(string $trackingNumber): array
    {
        return $this->client()->track($trackingNumber);
    }

    /**
     * Fetch this parcel's current status straight from Coliix, by its
     * courier_tracking_number ("code d'envoi") — the last entry in
     * track()'s "msg" history array. Feed the result straight into
     * mapDeliveryStatus() — untranslated, same raw french "status" label
     * Coliix itself reports (e.g. "Livré ", trailing space and all).
     *
     * Returns null when "msg" is empty (nothing tracked yet for this code).
     */
    public function getCurrentStatus(string $trackingNumber): ?string
    {
        $history = $this->track($trackingNumber)['msg'] ?? [];

        if (! is_array($history) || empty($history)) {
            return null;
        }

        return end($history)['status'] ?? null;
    }

    /**
     * Build an authenticated Coliix client from this account's stored credentials.
     */
    private function client(): ColiixClient
    {
        $credentials = json_decode($this->account->api_credentials, true) ?? [];

        return new ColiixClient($credentials['token'] ?? '');
    }
}
