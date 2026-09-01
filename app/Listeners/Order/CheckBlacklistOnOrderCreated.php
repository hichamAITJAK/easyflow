<?php

namespace App\Listeners\Order;

use App\Events\Order\ClientFlaggedBlacklist;
use App\Events\Order\OrderCreated;
use App\Models\Customer;
use App\Models\CustomerBlacklistEntry;

/**
 * Flags an order whose client phone matches a CustomerBlacklistEntry
 * (UC-10), and keeps Customer.is_blacklisted in sync with that match.
 * Runs synchronously — the flag must be visible to the assigned agent the
 * moment the confirmation screen loads, not after a queue cycle.
 *
 * Skipped entirely for is_test orders (UC-25): a test order's phone number
 * must never pollute blacklist scoring or be treated as a real match.
 */
class CheckBlacklistOnOrderCreated
{
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        if ($order->is_test) {
            return;
        }

        $isBlacklisted = CustomerBlacklistEntry::where('business_id', $order->business_id)
            ->where('phone_hash', $order->customer_phone_hash)
            ->exists();

        if (! $isBlacklisted) {
            return;
        }

        $order->update(['is_blacklist_flagged' => true]);

        // is_best_customer is cleared alongside is_blacklisted (UC-20's
        // rule: a blacklisted customer can never also be a best customer)
        // rather than left for the next delivery/return event to correct.
        Customer::where('business_id', $order->business_id)
            ->where('phone_hash', $order->customer_phone_hash)
            ->update(['is_blacklisted' => true, 'is_best_customer' => false]);

        ClientFlaggedBlacklist::dispatch($order);
    }
}
