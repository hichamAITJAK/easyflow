<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\Courier;
use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Http\Controllers\Controller;
use App\Models\CourierTrackingEvent;
use App\Models\Order;
use App\Services\Operations\Couriers\OnesstaService;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class OnesstaWebhookController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    /**
     * Receive an Onessta parcel status webhook (parcel.status_updated or
     * parcel.status_second_updated) and move the order along.
     *
     * One webhook serves every tenant: it is set up once on our partner
     * account, so the parcel is found by its tracking code, never by a URL
     * segment. X-Signature is an HMAC-SHA256 of the payload RE-ENCODED with
     * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES (Onessta's own
     * recipe), not of the raw body, and is required: without a configured
     * secret every call is refused, so nobody can forge a "delivered".
     *
     * Any 2xx is an acknowledgement and anything else is retried (3 times),
     * so a parcel we don't know, or a status we can't map, still answers 200.
     *
     * Docs: https://api.onessta.com/docs/partners (Webhooks)
     */
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.onessta.webhook_secret');
        $payload = $request->json()->all();

        $expected = hash_hmac('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);

        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Signature'))) {
            Log::warning('Onessta webhook rejected: bad or missing signature.');

            abort(401);
        }

        $parcel = $payload['data']['parcel'] ?? [];
        $code = (string) ($parcel['code'] ?? '');
        $status = (string) ($parcel['status'] ?? '');
        $statusSecond = isset($parcel['status_second']) ? (string) $parcel['status_second'] : null;

        $order = $code === '' ? null : Order::withoutGlobalScopes()
            ->where('courier_slug', Courier::ONESSTA->value)
            ->where('courier_tracking_number', $code)
            ->first();

        if (! $order || $status === '') {
            return $this->received($payload, $code);
        }

        try {
            $newStatus = (new OnesstaService)->mapParcelState($status, $statusSecond);
        } catch (InvalidArgumentException) {
            Log::warning("Onessta webhook: unknown status [{$status}] for order #{$order->id}; status unchanged.");
            $newStatus = null;
        }

        CourierTrackingEvent::withoutGlobalScopes()->insertOrIgnore([
            'business_id' => $order->business_id,
            'order_id' => $order->id,
            'status' => OnesstaService::label($statusSecond ?: $status),
            'delivery_status' => $newStatus?->value,
            'payment_state' => null,
            'happened_at' => $this->time($parcel['updated_at'] ?? $payload['timestamp'] ?? null),
            'created_at' => now(),
        ]);

        // A parcel already finished keeps its status, same as the poll.
        $finished = ! $order->is_delivery_active && $order->delivery_status !== null;

        if ($newStatus !== null && ! $finished && $newStatus !== $order->delivery_status) {
            $this->orderService->updateDeliveryStatus(
                $order,
                $newStatus,
                returnReasonCode: $newStatus === OrderDeliveryStatus::RETURNED_IN_TRANSIT ? OrderReturnReason::OTHER : null,
            );
        }

        return $this->received($payload, $code);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function received(array $payload, string $code): JsonResponse
    {
        return response()->json([
            'received' => true,
            'event_type' => $payload['event_type'] ?? null,
            'parcel_code' => $code,
        ]);
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
