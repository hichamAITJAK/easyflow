<?php

namespace App\Services\Operations\Couriers;

use App\DTOs\Onessta\OnesstaNewParcelDTO;
use App\DTOs\Onessta\OnesstaParcelDTO;
use App\Enums\Courier;
use App\Enums\OrderDeliveryStatus;
use App\Interfaces\DeliveryCourierInterface;
use App\Interfaces\ProvidesTrackingHistory;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\Order;
use App\Services\Connectivity\Onessta\OnesstaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

class OnesstaService implements DeliveryCourierInterface, ProvidesTrackingHistory
{
    /**
     * Onessta's French status labels, exactly as its /statuses list and the
     * tracking history write them, mapped to their codes, so a label and
     * its code always map the same way. Matched case-insensitively.
     */
    private const LABELS = [
        'Nouveau Colis' => 'NEW_PARCEL',
        'Attente De Ramassage' => 'WAITING_PICKUP',
        'Ramassé' => 'PICKED_UP',
        'Expédié' => 'SENT',
        'Reçu' => 'RECEIVED',
        'En Voyage' => 'ON_VYG',
        'En cours' => 'IN_PROGRESS',
        'Mise en distribution' => 'DISTRIBUTION',
        "Aujourd'hui" => 'TODAY',
        'Livré' => 'DELIVERED',
        'Reporté' => 'POSTPONED',
        'Reporté ( SUIVI )' => 'POSTPONED_TEAM',
        'Programmé' => 'PROGRAMED',
        'Pas de réponse + SMS' => 'NOANSWER',
        'Pas de reponse ( SUIVI )' => 'NOANSWER_TEAM',
        'Injoignable' => 'UNREACHABLE',
        'Injoignable ( SUIVI )' => 'UNREACHABLE_TEAM',
        'Boite Vocal' => 'VOICEMAIL',
        'Boite Vocal ( SUIVI )' => 'VOICEMAIL_TEAM',
        'Hors-zone' => 'OUT_OF_AREA',
        'Erreur Numero' => 'ERR_NUM',
        'Pas de Reponse Siege 01' => 'SWI_1',
        'Pas de Reponse siege 02' => 'SWI_2',
        'Pas de Reponse siege 03' => 'SWI_3',
        'Client intéressé' => 'INT',
        'A Relancé' => 'EXPIRED',
        'Changer destinataire' => 'REPLACE',
        'Refusé' => 'REFUSE',
        'Retourné' => 'RETURNED',
        'Annulé' => 'CANCELED',
        'Annulé ( SUIVI )' => 'CANCELED_TEAM',
        'Non Payé' => 'NOT_PAID',
        'Payé' => 'PAID',
        'Facturé' => 'INVOICED',
        'Remboursé' => 'REFUNDED',
        'Remboursement en cours' => 'BEING_REFUND',
    ];

    public function __construct(private readonly ?DeliveryAccount $account = null) {}

    /**
     * Create a parcel with Onessta for the given order, shipping to $city.
     *
     * Onessta takes its own numeric city ids, so the destination must be a
     * synced city carrying an external_courrier_id. The account's collect
     * city goes along as the pickup city when it has one.
     *
     * The parcel answer quotes no price; the costs come from this client's
     * own fees for the destination city (see cityFees()).
     *
     * @throws InvalidArgumentException if no city with an Onessta id is available.
     */
    public function addParcel(Order $order, ?DeleveryCourrierCity $city = null): OnesstaParcelDTO
    {
        $cityId = $city?->external_courrier_id;

        if (! $cityId) {
            throw new InvalidArgumentException('Onessta requires a destination city with an Onessta city id.');
        }

        $pickup = $this->account?->collectCity;

        $payload = (new OnesstaNewParcelDTO(
            code: (string) ($order->reference ?? $order->id),
            receiver: (string) $order->customer_name,
            phone: (string) $order->customer_phone,
            price: (float) $order->total_amount,
            cityId: (string) $cityId,
            cityName: $city->name,
            address: (string) $order->customer_address,
            pickupCityId: $pickup?->external_courrier_id,
            pickupCityName: $pickup?->name,
            note: $order->parcel_note,
            productNature: $order->parcel_nature,
            canOpen: (bool) ($order->parcel_open ?? true),
            replace: (bool) $order->parcel_replace,
        ))->toArray();

        $parcel = $this->client()->addParcel($payload);

        return OnesstaParcelDTO::fromArray($parcel, $this->cityFees((string) $cityId));
    }

    /**
     * Translate an Onessta status — its code (DISTRIBUTION) or its French
     * label (Mise en distribution) — into our OrderDeliveryStatus.
     *
     * Covers Onessta's whole /statuses list. Pre-pickup steps map to null
     * (our own AWAITING_PICKUP is set by createShipment()), as do plain
     * notes ("Client intéressé", "A Relancé") and the payment situations
     * (Payé, Facturé...), which say nothing about where the parcel is.
     *
     * @throws InvalidArgumentException if $status isn't one Onessta reports.
     */
    public function mapDeliveryStatus(string $status): ?OrderDeliveryStatus
    {
        $value = trim($status);
        $code = self::codeOf($value) ?? strtoupper($value);

        return match ($code) {
            'NEW_PARCEL', 'WAITING_PICKUP', 'INT', 'EXPIRED',
            'NOT_PAID', 'PAID', 'INVOICED', 'REFUNDED', 'BEING_REFUND' => null,
            'PICKED_UP', 'SENT', 'RECEIVED', 'ON_VYG', 'IN_PROGRESS' => OrderDeliveryStatus::IN_TRANSIT,
            'DISTRIBUTION', 'TODAY' => OrderDeliveryStatus::OUT_FOR_DELIVERY,
            'POSTPONED', 'POSTPONED_TEAM', 'PROGRAMED' => OrderDeliveryStatus::POSTPONED,
            'NOANSWER', 'NOANSWER_TEAM', 'UNREACHABLE', 'UNREACHABLE_TEAM',
            'VOICEMAIL', 'VOICEMAIL_TEAM', 'OUT_OF_AREA', 'ERR_NUM',
            'SWI_1', 'SWI_2', 'SWI_3' => OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED,
            'REPLACE' => OrderDeliveryStatus::CHANGED,
            'REFUSE', 'REFUSED' => OrderDeliveryStatus::REFUSED,
            'DELIVERED' => OrderDeliveryStatus::DELIVERED,
            'RETURNED' => OrderDeliveryStatus::RETURNED_IN_TRANSIT,
            'CANCELED', 'CANCELLED', 'CANCELED_TEAM' => OrderDeliveryStatus::CANCELLED_AT_COURIER,
            default => throw new InvalidArgumentException("Unknown Onessta delivery status [{$status}]."),
        };
    }

    /**
     * Onessta's French label for a status code ("DISTRIBUTION" → "Mise en
     * distribution"), the way the tracking history writes it; the code
     * itself when it has no known label.
     */
    public static function label(string $code): string
    {
        $label = array_search(strtoupper(trim($code)), self::LABELS, true);

        return $label === false ? $code : $label;
    }

    /**
     * The code behind one of Onessta's labels, whatever its case; null for
     * anything that isn't a known label.
     */
    private static function codeOf(string $label): ?string
    {
        foreach (self::LABELS as $known => $code) {
            if (mb_strtolower($known) === mb_strtolower(trim($label))) {
                return $code;
            }
        }

        return null;
    }

    /**
     * What a parcel's main + secondary status pair means for us. The
     * secondary status (PROGRAMED, NOANSWER...) is the more precise one
     * while the main status is a plain IN_PROGRESS, so it wins whenever it
     * maps to something; otherwise the main status decides.
     *
     * @throws InvalidArgumentException if the main status isn't one Onessta reports.
     */
    public function mapParcelState(string $status, ?string $statusSecond): ?OrderDeliveryStatus
    {
        if ($statusSecond !== null && trim($statusSecond) !== '') {
            try {
                $secondary = $this->mapDeliveryStatus($statusSecond);
            } catch (InvalidArgumentException) {
                $secondary = null;
            }

            if ($secondary !== null) {
                return $secondary;
            }
        }

        return $this->mapDeliveryStatus($status);
    }

    /**
     * The parcel's latest status label, from its tracking history, falling
     * back to get_by_code's own STATUS. Null when Onessta doesn't know this
     * code, so one unknown parcel never fails the whole poll.
     *
     * @throws RequestException|ConnectionException on a real API failure.
     */
    public function getCurrentStatus(string $trackingNumber): ?string
    {
        $steps = $this->getTrackingHistory($trackingNumber);

        if ($steps !== []) {
            return $steps[array_key_last($steps)]['status'];
        }

        try {
            $parcel = $this->client()->getParcel($trackingNumber);
        } catch (RequestException $e) {
            if ($this->isNotFound($e)) {
                return null;
            }

            throw $e;
        }

        $status = $parcel['STATUS'] ?? $parcel['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * The parcel's steps, oldest first. Onessta lists them newest first,
     * each with its code and French label; the label is kept (it is what
     * admins read in the parcel history) and maps the same as its code.
     *
     * @return list<array{status: string, happened_at: Carbon|null, payment_state: string|null}>
     *
     * @throws RequestException|ConnectionException on a real API failure.
     */
    public function getTrackingHistory(string $trackingNumber): array
    {
        try {
            $history = $this->client()->getTracking($trackingNumber);
        } catch (RequestException $e) {
            if ($this->isNotFound($e)) {
                return [];
            }

            throw $e;
        }

        $steps = [];

        foreach (array_reverse($history) as $step) {
            if (! is_array($step)) {
                continue;
            }

            $label = trim((string) ($step['name'] ?? ''));
            $code = trim((string) ($step['status'] ?? ''));

            if ($label === '' && $code === '') {
                continue;
            }

            $steps[] = [
                'status' => $label !== '' && self::codeOf($label) !== null ? $label : ($code ?: $label),
                'happened_at' => $this->parseTime($step['created_at'] ?? null),
                'payment_state' => null,
            ];
        }

        return $steps;
    }

    /**
     * Change the receiver of a parcel still on its way (same city).
     *
     * @throws RequestException|ConnectionException
     */
    public function changeRecipient(string $trackingNumber, string $receiver, string $phone, string $address, ?string $note = null): void
    {
        $this->client()->changeRecipient(array_filter([
            'code' => $trackingNumber,
            'receiver' => mb_substr($receiver, 0, 30),
            'phone' => preg_replace('/\D/', '', $phone),
            'address' => mb_substr($address, 0, 255),
            'note' => $note,
        ], fn ($value) => $value !== null));
    }

    /**
     * Upsert Onessta's city list (ids + names) from this account's keys.
     * The list itself is the same for every client; only the fees differ,
     * and those are read per account at shipping time instead.
     *
     * @throws RuntimeException if the courier isn't seeded.
     * @throws RequestException|ConnectionException
     */
    public function syncCities(): void
    {
        self::upsertCities($this->client()->getCities());
    }

    /**
     * @param  list<array<string, mixed>>  $cities
     *
     * @throws RuntimeException if the courier isn't seeded.
     */
    public static function upsertCities(array $cities): int
    {
        $courier = DeliveryCourrier::where('slug', Courier::ONESSTA->value)->first()
            ?? throw new RuntimeException('Onessta courier is not seeded yet');

        $count = 0;

        foreach ($cities as $city) {
            $name = trim((string) ($city['name'] ?? ''));

            if ($name === '' || ! isset($city['id'])) {
                continue;
            }

            DeleveryCourrierCity::updateOrCreate(
                ['courrier_id' => $courier->id, 'external_courrier_id' => (string) $city['id']],
                ['name' => $name],
            );
            $count++;
        }

        return $count;
    }

    /**
     * This client's delivered / returned / refused fees for one city,
     * cached for a few hours per account. A failed lookup only leaves the
     * costs empty — it never fails a parcel that Onessta already created.
     *
     * @return array{delivered?: ?float, returned?: ?float, refused?: ?float}
     */
    private function cityFees(string $cityId): array
    {
        try {
            $fees = Cache::remember(
                'onessta:city-fees:'.($this->account?->id ?? 0),
                now()->addHours(6),
                fn () => collect($this->client()->getCities())
                    ->mapWithKeys(fn (array $city) => [(string) ($city['id'] ?? '') => [
                        'delivered' => is_numeric($city['delivered_fees'] ?? null) ? (float) $city['delivered_fees'] : null,
                        'returned' => is_numeric($city['retourned_fees'] ?? null) ? (float) $city['retourned_fees'] : null,
                        'refused' => is_numeric($city['refused_fees'] ?? null) ? (float) $city['refused_fees'] : null,
                    ]])
                    ->all(),
            );
        } catch (RequestException|ConnectionException) {
            return [];
        }

        return $fees[$cityId] ?? [];
    }

    /**
     * Onessta answers an unknown parcel code with "Parcel(s) with Code: X
     * not found." — the same 401 a bad token gets, so the message decides.
     */
    private function isNotFound(RequestException $e): bool
    {
        return str_contains(strtolower((string) $e->response->json('message')), 'not found');
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * An authenticated client: our partner token plus this account's own
     * Client-ID and API-Key.
     */
    private function client(): OnesstaClient
    {
        $credentials = json_decode((string) $this->account?->api_credentials, true) ?? [];

        return new OnesstaClient(
            (string) config('services.onessta.partner_token'),
            (string) ($credentials['client_id'] ?? ''),
            (string) ($credentials['api_key'] ?? ''),
        );
    }
}
