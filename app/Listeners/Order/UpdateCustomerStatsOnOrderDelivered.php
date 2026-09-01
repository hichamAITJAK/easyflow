<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderDelivered;
use App\Models\Customer;

/**
 * Bumps the matching Customer's delivered_orders_count and recomputes
 * is_best_customer (UC-20) when one of their orders is delivered.
 *
 * Skipped for is_test orders (UC-25) — a test order never reaches a real
 * Customer row in the first place (UpsertCustomerOnOrderCreated skips it
 * too), so there is nothing to update.
 */
class UpdateCustomerStatsOnOrderDelivered
{
    use RecomputesBestCustomer;

    public function handle(OrderDelivered $event): void
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

        $customer->delivered_orders_count++;
        $this->recomputeBestCustomer($customer);
        $customer->save();
    }
}
