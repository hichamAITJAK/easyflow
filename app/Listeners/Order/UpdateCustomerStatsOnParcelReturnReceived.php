<?php

namespace App\Listeners\Order;

use App\Events\Order\ParcelReturnReceived;
use App\Models\Customer;

/**
 * Bumps the matching Customer's returned_orders_count and recomputes
 * is_best_customer (UC-20) on a warehouse-confirmed physical return.
 * Deliberately keyed off ParcelReturnReceived, not OrderReturnedInTransit —
 * the courier's return claim alone isn't a confirmed fact yet (same rule
 * ParcelReturnReceived's own docblock establishes for blacklist scoring).
 *
 * Skipped for is_test orders (UC-25) — see UpsertCustomerOnOrderCreated.
 */
class UpdateCustomerStatsOnParcelReturnReceived
{
    use RecomputesBestCustomer;

    public function handle(ParcelReturnReceived $event): void
    {
        $order = $event->order;

        if ($order->is_test) {
            return;
        }

        $customer = Customer::where('business_id', $order->business_id)
            ->where('phone_hash', $order->customer_phone_hash)
            ->first();

        if (! $customer) {
            return;
        }

        $customer->returned_orders_count++;
        $this->recomputeBestCustomer($customer);
        $customer->save();
    }
}
