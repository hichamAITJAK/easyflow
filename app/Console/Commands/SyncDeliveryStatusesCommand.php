<?php

namespace App\Console\Commands;

use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
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
    protected $signature = 'orders:sync-delivery-statuses {--business= : Only sync orders for this business ID}';

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

        $orders = Order::query()
            ->where('is_delivery_active', true)
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

                try {
                    $rawStatus = $courier->getCurrentStatus($order->courier_tracking_number);

                    if ($rawStatus === null) {
                        continue;
                    }

                    $newStatus = $courier->mapDeliveryStatus($rawStatus);

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
}
