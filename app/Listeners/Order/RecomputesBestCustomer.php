<?php

namespace App\Listeners\Order;

use App\Models\Customer;

/**
 * Shared UC-20 best-customer rule, applied wherever a Customer's delivered/
 * returned counters change: at least 3 delivered orders, zero returns, and
 * not blacklisted. Mutates the given Customer in place — callers are
 * responsible for saving it.
 */
trait RecomputesBestCustomer
{
    private const MIN_DELIVERED_ORDERS = 3;

    private function recomputeBestCustomer(Customer $customer): void
    {
        $customer->is_best_customer = $customer->delivered_orders_count >= self::MIN_DELIVERED_ORDERS
            && $customer->returned_orders_count === 0
            && ! $customer->is_blacklisted;
    }
}
