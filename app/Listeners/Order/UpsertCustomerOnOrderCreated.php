<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderCreated;
use App\Models\Customer;

/**
 * Finds or creates the Customer row for a new order's phone number (UC-20's
 * prerequisite: the customers list, best-customer filter, and blacklist
 * metrics all already exist and are wired, but nothing ever wrote to this
 * table). Snapshots the latest name/address/city seen and bumps
 * orders_count/last_order_at on every order, real or repeat.
 *
 * Skipped entirely for is_test orders (UC-25), same rule as
 * CheckBlacklistOnOrderCreated/CheckDuplicateOnOrderCreated: a test order's
 * (likely fake or reused) phone number must never create or update a real
 * customer record.
 */
class UpsertCustomerOnOrderCreated
{
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        if ($order->is_test) {
            return;
        }

        $customer = Customer::where('business_id', $order->business_id)
            ->where('phone_hash', $order->customer_phone_hash)
            ->first();

        if ($customer) {
            $customer->update([
                'name' => $order->customer_name,
                'phone' => $order->customer_phone,
                'address' => $order->customer_address,
                'city' => $order->customer_city,
                'orders_count' => $customer->orders_count + 1,
                'last_order_at' => $order->ordered_at ?? $order->created_at,
            ]);

            return;
        }

        Customer::create([
            'business_id' => $order->business_id,
            'name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'phone_hash' => $order->customer_phone_hash,
            'address' => $order->customer_address,
            'city' => $order->customer_city,
            'orders_count' => 1,
            'delivered_orders_count' => 0,
            'returned_orders_count' => 0,
            'last_order_at' => $order->ordered_at ?? $order->created_at,
            'is_best_customer' => false,
            'is_blacklisted' => false,
        ]);
    }
}
