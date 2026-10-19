<?php

namespace App\Console\Commands;

use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Interfaces\DeliveryCourierInterface;
use App\Interfaces\ProvidesTrackingHistory;
use App\Models\CourierTrackingEvent;
use App\Models\Order;
use App\Services\Operations\IntegrationManagerService;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Throwable;

class SyncDeliveryStatusesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:sync-delivery-statuses
        {--business= : Only sync orders for this business ID}
        {--history : Also fetch the courier history of finished parcels (delivered, cancelled…), without changing their status}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll couriers for delivery status updates on shipped, non-terminal orders';

    public function __construct(
        private readonly IntegrationManagerService $manager,
        private readonly OrderService $orderService,
    ) {
        parent::__construct();
    }

    /**
     * Most couriers give no webhook for delivery status changes, so this
     * is the only thing keeping orders.delivery_status from going stale
     * once a parcel ships. is_delivery_active (maintained solely by
     * OrderService::updateDeliveryStatus()/createShipment()) is what makes
     * "still in flight" a cheap indexed lookup instead of a
     * delivery_status NOT IN (...) scan.
     *
     * Orders are grouped by delivery_account_id in PHP after the fetch, so
     * each courier client is built once per account rather than once per
     * order — that grouping isn't pushed into the query/index itself.
     */
    public function handle(): int
    {
        $businessId = $this->option('business');

        // Parcels still on their way, plus orders that carry a tracking
        // number but have no delivery status yet (a parcel created outside
        // EasyFlow): those take the courier's status on their first sync.
        $orders = Order::query()
            ->when(
                ! $this->option('history'),
                fn ($query) => $query->where(fn ($query) => $query->where('is_delivery_active', true)->orWhereNull('delivery_status')),
            )
            ->whereNotNull('courier_tracking_number')
            ->whereNotNull('delivery_account_id')
            ->when($businessId, fn ($query) => $query->where('business_id', $businessId))
            ->with('deliveryAccount.courier')
            ->get();

        $failures = 0;

        foreach ($orders->groupBy('delivery_account_id') as $accountOrders) {
            $account = $accountOrders->first()->deliveryAccount;

            if (! $account) {
                continue;
            }

            $courier = $this->manager->courierForAccount($account);
            $updated = 0;
            $checked = 0;

            foreach ($accountOrders as $order) {
                $checked++;

                // A finished parcel (only reached with --history) gets its
                // history filled in; its status is left exactly as it is.
                $finished = ! $order->is_delivery_active && $order->delivery_status !== null;

                if ($finished && ! $courier instanceof ProvidesTrackingHistory) {
                    continue;
                }

                try {
                    $newStatus = $courier instanceof ProvidesTrackingHistory
                        ? $this->syncHistory($order, $courier)
                        : $this->currentStatus($order, $courier);

                    if ($finished) {
                        continue;
                    }

                    if ($newStatus !== null && $newStatus !== $order->delivery_status) {
                        // Couriers only ever give us a raw status label, never
                        // a structured reason — RETURNED_IN_TRANSIT is the one
                        // transition updateDeliveryStatus() requires a reason
                        // code for, so OTHER stands in for "courier-reported,
                        // no further detail available" here.
                        $returnReasonCode = $newStatus === OrderDeliveryStatus::RETURNED_IN_TRANSIT
                            ? OrderReturnReason::OTHER
                            : null;

                        $this->orderService->updateDeliveryStatus($order, $newStatus, returnReasonCode: $returnReasonCode);
                        $updated++;
                    }
                } catch (RequestException|ConnectionException|InvalidArgumentException $e) {
                    $failures++;
                    $this->warn("Order #{$order->id} [{$order->reference}]: {$e->getMessage()}");
                } catch (Throwable $e) {
                    $failures++;
                    $this->error("Order #{$order->id} [{$order->reference}]: {$e->getMessage()}");
                }
            }

            $this->info("{$account->label} ({$account->courier->name}): {$checked} checked, {$updated} updated.");
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The courier's latest status for a courier that only reports that.
     */
    private function currentStatus(Order $order, DeliveryCourierInterface $courier): ?OrderDeliveryStatus
    {
        $rawStatus = $courier->getCurrentStatus($order->courier_tracking_number);

        return $rawStatus === null ? null : $courier->mapDeliveryStatus($rawStatus);
    }

    /**
     * Saves every step of the parcel's journey (new steps only; the unique
     * key skips the ones already stored) and returns the status the order
     * should take.
     *
     * An order already tracked follows the latest step, as before. An order
     * with no delivery status yet takes the latest step that means a status
     * (a note like "Client intéressé" maps to none), and "awaiting pickup"
     * when the parcel only exists at the courier so far.
     */
    private function syncHistory(Order $order, DeliveryCourierInterface&ProvidesTrackingHistory $courier): ?OrderDeliveryStatus
    {
        $steps = $courier->getTrackingHistory($order->courier_tracking_number);

        if ($steps === []) {
            return null;
        }

        $mapped = array_map(function (array $step) use ($courier) {
            try {
                return $courier->mapDeliveryStatus($step['status']);
            } catch (InvalidArgumentException) {
                return null;
            }
        }, $steps);

        CourierTrackingEvent::withoutGlobalScopes()->insertOrIgnore(array_map(fn (array $step, ?OrderDeliveryStatus $status) => [
            'business_id' => $order->business_id,
            'order_id' => $order->id,
            'status' => $step['status'],
            'delivery_status' => $status?->value,
            'payment_state' => $step['payment_state'],
            'happened_at' => $step['happened_at'],
            'created_at' => now(),
        ], $steps, $mapped));

        if ($order->delivery_status !== null) {
            // The latest step decides. A label we don't know is kept in
            // the history exactly as the courier wrote it, and the order's
            // status is left as it is rather than failing the parcel.
            $last = $steps[array_key_last($steps)]['status'];

            try {
                return $courier->mapDeliveryStatus($last);
            } catch (InvalidArgumentException) {
                $this->warn("Order #{$order->id} [{$order->reference}]: unknown courier status [{$last}] kept in the history; status unchanged.");

                return null;
            }
        }

        foreach (array_reverse($mapped) as $status) {
            if ($status !== null) {
                return $status;
            }
        }

        return OrderDeliveryStatus::AWAITING_PICKUP;
    }
}
