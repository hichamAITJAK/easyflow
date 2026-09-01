<?php

namespace App\Interfaces;

use App\DTOs\Ameex\AmeexParcelDTO;
use App\DTOs\Coliix\ColiixParcelDTO;
use App\DTOs\ForceLog\ForceLogParcelDTO;
use App\DTOs\OzonExpress\OzonExpressParcelDTO;
use App\DTOs\Sendit\SenditParcelDTO;
use App\Enums\OrderDeliveryStatus;
use App\Models\DeleveryCourrierCity;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

interface DeliveryCourierInterface
{
    /**
     * Register a parcel for the given order with this courier. $city is the
     * destination city; implementations that also need a pickup/origin city
     * (e.g. Sendit) read it off their own $account's collectCity relation.
     *
     * Every courier's own parcel-result DTO shares the same property
     * names for the fields callers actually need (trackingNumber,
     * deliveryCost, returnedCost, refusedCost) — see SenditParcelDTO /
     * OzonExpressParcelDTO / ColiixParcelDTO — so callers can read those
     * uniformly without caring which concrete courier answered.
     */
    public function addParcel(
        Order $order,
        ?DeleveryCourrierCity $city = null
    ): SenditParcelDTO
    |OzonExpressParcelDTO
    |ColiixParcelDTO
    |ForceLogParcelDTO
    |AmeexParcelDTO;

    /**
     * Translate this courier's own raw delivery/tracking status string into
     * our shared OrderDeliveryStatus — the only place that needs to know
     * what a given courier calls "in transit" or "delivered". Callers
     * (tracking sync, webhooks) write the result straight to
     * orders.delivery_status without ever seeing the courier's own
     * vocabulary.
     *
     * Returns null for a courier status that doesn't correspond to any of
     * our mappable statuses (e.g. Sendit's pre-pickup statuses — those are
     * ours to set via createShipment(), not something a courier status
     * update should ever overwrite) — callers should treat null as "no
     * change", not an error.
     *
     * @throws \InvalidArgumentException if $status is not a value this courier reports at all.
     */
    public function mapDeliveryStatus(string $status): ?OrderDeliveryStatus;

    /**
     * Fetch this courier's current raw delivery status label for a shipped
     * parcel, by its courier_tracking_number. Feed the result straight into
     * mapDeliveryStatus() — this method does no translation of its own, so
     * callers (the delivery-status poll) stay agnostic to each courier's
     * own vocabulary.
     *
     * Returns null if the courier has nothing to report yet for this
     * tracking number (e.g. not found on their side) — callers should
     * treat that as "no change", not an error.
     *
     * @throws RequestException|ConnectionException on a real API failure.
     */
    public function getCurrentStatus(string $trackingNumber): ?string;

    public function syncCities(): void;
}
