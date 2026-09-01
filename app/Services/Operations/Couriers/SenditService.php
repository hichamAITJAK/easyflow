<?php

namespace App\Services\Operations\Couriers;

use App\DTOs\Sendit\SenditDistrictDTO;
use App\DTOs\Sendit\SenditNewParcelDTO;
use App\DTOs\Sendit\SenditParcelDTO;
use App\Enums\OrderDeliveryStatus;
use App\Interfaces\DeliveryCourierInterface;
use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryAccount;
use App\Models\Order;
use App\Services\Connectivity\Sendit\AuthService;
use App\Services\Connectivity\Sendit\SenditClient;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

class SenditService implements DeliveryCourierInterface
{
    public function __construct(private readonly ?DeliveryAccount $account = null) {}

    /**
     * Create a parcel with Sendit for the given order, shipping to $city.
     * Sendit also requires a pickup/origin district, taken from this
     * account's own collectCity — not the order's destination city.
     *
     * @throws InvalidArgumentException if $city is missing, or the account
     *                                  has no usable pickup city configured.
     */
    public function addParcel(Order $order, ?DeleveryCourrierCity $city = null): SenditParcelDTO
    {
        if (! $city || ! $city->external_courrier_id) {
            throw new InvalidArgumentException('Sendit requires a destination city with an external courier id.');
        }

        if (! $this->account?->collectCity?->external_courrier_id) {
            throw new InvalidArgumentException("Delivery account [{$this->account?->label}] has no pickup city configured — set a collection city on the account before shipping with Sendit.");
        }

        $payload = (new SenditNewParcelDTO(
            districtId: (int) $city->external_courrier_id,
            name: $order->customer_name,
            phone: $order->customer_phone,
            address: $order->customer_address,
            amount: (float) $order->total_amount,
            pickupDistrictId: (int) $this->account->collectCity->external_courrier_id,
            comment: $order->parcel_note,
            reference: $order->reference,
            allowOpen: $order->parcel_open === null ? null : (bool) $order->parcel_open,
        ))->toArray();

        return $this->execute(
            fn () => SenditParcelDTO::fromArray(
                $this->client()->parcel()->createParcel($payload)['data'] ?? []
            )
        );
    }

    /**
     * Translate one of Sendit's own delivery status codes (as listed by
     * their /delivery-statuses endpoint) into our OrderDeliveryStatus.
     *
     * PENDING/TO_PREPARE/NEW_DESTINATION/TOPICKUP are all pre-pickup — the
     * parcel isn't with the courier's network yet, so there's nothing here
     * for us to reflect; they map to null rather than a status, since
     * AWAITING_PICKUP is a state we set ourselves at createShipment() time
     * and a courier status update should never re-set it.
     *
     * Sendit has no status corresponding to OrderDeliveryStatus::CHANGED or
     * ::RETURNED_IN_TRANSIT — this courier just doesn't report either
     * concept, so no code below produces them.
     *
     * @throws InvalidArgumentException if $status isn't one Sendit reports.
     */
    public function mapDeliveryStatus(string $status): ?OrderDeliveryStatus
    {
        return match ($status) {
            'PENDING', 'TO_PREPARE', 'NEW_DESTINATION', 'TOPICKUP' => null,
            'PICKEDUP', 'WAREHOUSE', 'TRANSIT', 'DISTRIBUTED', 'DELIVERING' => OrderDeliveryStatus::IN_TRANSIT,
            'UNREACHABLE' => OrderDeliveryStatus::DELIVERY_ATTEMPT_FAILED,
            'POSTPONED' => OrderDeliveryStatus::POSTPONED,
            'CANCELED' => OrderDeliveryStatus::CANCELLED_AT_COURIER,
            'REJECTED' => OrderDeliveryStatus::REFUSED,
            'DELIVERED' => OrderDeliveryStatus::DELIVERED,
            default => throw new InvalidArgumentException("Unknown Sendit delivery status [{$status}]."),
        };
    }

    /**
     * Fetch this parcel's current status straight from Sendit, by the
     * courier_tracking_number it assigned at addParcel() time
     * (SenditParcelDTO::$trackingNumber, Sendit's own "code"). Feed the
     * result straight into mapDeliveryStatus() — untranslated, same raw
     * code Sendit itself reports (e.g. "DELIVERED").
     */
    public function getCurrentStatus(string $trackingNumber): ?string
    {
        return $this->execute(
            fn () => SenditParcelDTO::fromArray(
                $this->client()->parcel()->getParcel($trackingNumber)['data'] ?? []
            )->status
        );
    }

    /**
     * Sync the list of cities Sendit covers.
     */
    public function syncCities(): void
    {
        throw new \RuntimeException('Sendit city sync is not implemented yet.');
    }

    /**
     * List the districts/cities Sendit currently covers.
     *
     * @return SenditDistrictDTO[]
     */
    public function listDistricts(): array
    {
        return $this->execute(
            fn () => SenditDistrictDTO::fromList($this->client()->district()->getDistricts())
        );
    }

    // ----------------------------------------------------------------
    // Prepare Connectivity Bridge
    // ----------------------------------------------------------------

    /**
     * Run a Sendit API call, transparently refreshing the access token and
     * retrying once if the call fails with an unauthorized (401) response.
     */
    private function execute(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (RequestException $e) {
            if ($e->response->status() !== 401) {
                throw $e;
            }

            $this->refreshToken();

            return $callback();
        }
    }

    /**
     * Exchange the stored public/secret key pair for a new token and persist it.
     */
    private function refreshToken(): void
    {
        $credentials = json_decode($this->account->api_credentials, true) ?? [];

        $response = (new AuthService(''))->login(
            publicKey: $credentials['public_key'] ?? '',
            secretKey: $credentials['secret_key'] ?? '',
        );

        $credentials['token'] = $response['token'] ?? '';

        $this->account->update([
            'api_credentials' => json_encode($credentials),
        ]);
    }

    /**
     * Build an authenticated Sendit client from this account's stored credentials.
     */
    private function client(): SenditClient
    {
        $credentials = json_decode($this->account->api_credentials, true) ?? [];

        return new SenditClient($credentials['token'] ?? '');
    }
}
