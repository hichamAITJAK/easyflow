<?php

namespace App\Services\Operations\Couriers;

use App\DTOs\Coliix\ColiixNewParcelDTO;
use App\DTOs\Coliix\ColiixParcelDTO;
use App\Enums\OrderDeliveryStatus;
use App\Interfaces\DeliveryCourierInterface;
use App\Interfaces\ProvidesTrackingHistory;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Services\Connectivity\Coliix\ColiixClient;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ColiixService implements DeliveryCourierInterface, ProvidesTrackingHistory
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

    public function mapDeliveryStatus(string $status): ?OrderDeliveryStatus
    {

        // "Reporté" comes with the new delivery date appended
        // ("Reporté 2026-10-20"); the label is what we match on.
        $label = trim(preg_replace('/\s+\d{4}-\d{2}-\d{2}\s*$/', '', $status) ?? $status);

        return match ($label) {
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

    public function getCurrentStatus(string $trackingNumber): ?string
    {
        $history = $this->track($trackingNumber)['msg'] ?? [];

        if (! is_array($history) || empty($history)) {
            return null;
        }

        return end($history)['status'] ?? null;
    }

    /**
     * Every step of the parcel's journey from track()'s "msg" array, oldest
     * first: the status label (trimmed), Coliix's own time for the step,
     * and the payment state it reports alongside ("Payé" / "Non Payé").
     *
     * @return list<array{status: string, happened_at: Carbon|null, payment_state: string|null}>
     */
    public function getTrackingHistory(string $trackingNumber): array
    {
        $history = $this->track($trackingNumber)['msg'] ?? [];

        if (! is_array($history)) {
            return [];
        }

        $steps = [];

        foreach ($history as $step) {
            if (! is_array($step) || trim((string) ($step['status'] ?? '')) === '') {
                continue;
            }

            $steps[] = [
                'status' => trim((string) $step['status']),
                'happened_at' => $this->parseTime($step['time'] ?? null),
                'payment_state' => isset($step['etat']) ? trim((string) $step['etat']) : null,
            ];
        }

        return $steps;
    }

    /**
     * Coliix writes times as "2026-10-15 15:11 :22" (a stray space before
     * the seconds); null when the value can't be read.
     */
    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalised = preg_replace('/\s*:\s*/', ':', trim($value));

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $normalised);
        } catch (\Throwable) {
            return null;
        }
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
