<?php

namespace App\Services\Operations\Couriers;

use App\DTOs\OzonExpress\OzonExpressCityDTO;
use App\DTOs\OzonExpress\OzonExpressNewParcelDTO;
use App\DTOs\OzonExpress\OzonExpressParcelDTO;
use App\Enums\OrderDeliveryStatus;
use App\Interfaces\DeliveryCourierInterface;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Services\Connectivity\OzonExpress\OzonExpressClient;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

class OzonExpressService implements DeliveryCourierInterface
{
    public function __construct(private readonly ?DeliveryAccount $account = null) {}

    /**
     * Create a parcel with OzonExpress for the given order, shipping to $city.
     *
     * @throws InvalidArgumentException if $city is missing, or has no external courier id.
     */
    public function addParcel(Order $order, ?DeleveryCourrierCity $city = null): OzonExpressParcelDTO
    {
        if (! $city || ! $city->external_courrier_id) {
            throw new InvalidArgumentException("City [{$city?->name}] has no external courier id — sync the courier's cities before shipping to it.");
        }

        $payload = (new OzonExpressNewParcelDTO(
            receiver: $order->customer_name,
            phone: $order->customer_phone,
            cityId: (int) $city->external_courrier_id,
            address: $order->customer_address,
            price: (float) $order->total_amount,
            stock: false,
            trackingNumber: $order->reference,
            note: $order->parcel_note,
            nature: $order->parcel_nature,
            open: $order->parcel_open === null ? null : (int) $order->parcel_open,
            fragile: $order->parcel_fragile,
            replace: $order->parcel_replace,
            products: $order->parcel_products,
        ))->toArray();

        return OzonExpressParcelDTO::fromArray(
            $this->client()->createParcel($payload)
        );
    }

    /**
     * Translate one of OzonExpress's own delivery status labels (the
     * french-language STATUT values from its tracking/parcel-info HISTORY,
     * e.g. "Livré", "Retourné") into our OrderDeliveryStatus.
     *
     * "Nouveau Colis"/"Attente De Ramassage" are pre-pickup — the parcel
     * isn't with the courier's network yet, so there's nothing here for us
     * to reflect; they map to null rather than a status, for the same
     * reason Sendit's pre-pickup statuses do (see
     * SenditService::mapDeliveryStatus()).
     *
     * "client intéressé" is an informational note logged mid-delivery-attempt
     * (seen between "Mise en distribution" and "Livré" in a real delivered
     * order's history), not a status transition of its own — it maps to
     * null so it doesn't regress an order already at OUT_FOR_DELIVERY.
     *
     * OzonExpress has no status corresponding to OrderDeliveryStatus::CHANGED
     * — this courier just doesn't report that concept, so no code below
     * produces it.
     *
     * @throws InvalidArgumentException if $status isn't one OzonExpress reports.
     */
    public function mapDeliveryStatus(string $status): ?OrderDeliveryStatus
    {
        return match ($status) {
            'Nouveau Colis', 'Attente De Ramassage', 'client intéressé' => null,
            'Ramassé', 'Expédié', 'Reçu' => OrderDeliveryStatus::IN_TRANSIT,
            'Mise en distribution' => OrderDeliveryStatus::OUT_FOR_DELIVERY,
            'Pas de réponse + SMS', 'Pas de réponse J+2', 'Pas de réponse J+3' => OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED,
            'Livré' => OrderDeliveryStatus::DELIVERED,
            'Retourné' => OrderDeliveryStatus::RETURNED_IN_TRANSIT,
            default => throw new InvalidArgumentException("Unknown OzonExpress delivery status [{$status}]."),
        };
    }

    /**
     * Extract the assigned delivery driver's name/phone from a "Mise en
     * distribution" HISTORY entry's COMMENT, e.g.:
     * "| Livreur: ABDELLAH BENSEBAITAI | Commentaire: &lt;b&gt;Livreur: &lt;/b&gt;ABDELLAH BENSEBAITAI &lt;br /&gt;&lt;b&gt;Téléphone: &lt;/b&gt;0760855619"
     *
     * Both a plain "Livreur: NAME" prefix and the HTML-escaped
     * "<b>Téléphone: </b>NUMBER" form appear in real responses — this reads
     * whichever is present rather than assuming one specific shape.
     *
     * @return array{name: ?string, phone: ?string}
     */
    public function extractDriverInfo(string $comment): array
    {
        $decoded = html_entity_decode($comment, ENT_QUOTES | ENT_HTML5);

        preg_match('/Livreur\s*:\s*<\/b>\s*([^<|]+)/u', $decoded, $nameMatch)
            || preg_match('/Livreur\s*:\s*([^|<]+)/u', $decoded, $nameMatch);

        preg_match('/T[ée]l[ée]phone\s*:\s*<\/b>\s*([^<|]+)/u', $decoded, $phoneMatch)
            || preg_match('/T[ée]l[ée]phone\s*:\s*([^|<]+)/u', $decoded, $phoneMatch);

        return [
            'name' => isset($nameMatch[1]) ? trim($nameMatch[1]) : null,
            'phone' => isset($phoneMatch[1]) ? trim($phoneMatch[1]) : null,
        ];
    }

    /**
     * Fetch this parcel's current status straight from OzonExpress, by its
     * courier_tracking_number — the last entry of the tracking HISTORY
     * (TRACKING.LAST_TRACKING.STATUT). Feed the result straight into
     * mapDeliveryStatus() — untranslated, same raw french STATUT label
     * OzonExpress itself reports (e.g. "Livré").
     *
     * getTracking() throws a RequestException for two very different
     * causes, both surfaced the same way: an actual HTTP-level failure
     * (network/5xx — response()->failed()), or a business-level "not
     * found" (HTTP 200, but TRACKING.RESULT !== "SUCCESS"). Only the
     * latter means "nothing to report yet" per this interface's null
     * contract — an HTTP-level failure is a real outage and must
     * propagate so the delivery-status poll counts it as a failure
     * instead of silently treating it as "no status".
     */
    public function getCurrentStatus(string $trackingNumber): ?string
    {
        try {
            $response = $this->client()->getTracking($trackingNumber);
        } catch (RequestException $e) {
            if ($e->response->failed()) {
                throw $e;
            }

            return null;
        }

        return $response['TRACKING']['LAST_TRACKING']['STATUT'] ?? null;
    }

    /**
     * Sync the list of cities OzonExpress covers.
     */
    public function syncCities(): void
    {
        throw new \RuntimeException('OzonExpress city sync is not implemented yet.');
    }

    /**
     * List the cities OzonExpress currently covers. Public endpoint — no
     * account/credentials required.
     *
     * @return OzonExpressCityDTO[]
     */
    public function listCities(): array
    {
        return OzonExpressCityDTO::fromList((new OzonExpressClient('', ''))->getCities());
    }

    /**
     * Build an authenticated OzonExpress client from this account's stored credentials.
     */
    private function client(): OzonExpressClient
    {
        $credentials = json_decode($this->account->api_credentials, true) ?? [];

        return new OzonExpressClient(
            $credentials['ozon_id'] ?? '',
            $credentials['api_key'] ?? '',
        );
    }
}
