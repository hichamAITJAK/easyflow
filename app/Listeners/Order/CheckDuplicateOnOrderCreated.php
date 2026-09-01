<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderCreated;
use App\Models\Order;

/**
 * Flags an order whose client phone matches another recent order for the
 * same business (UC-10). Runs synchronously, same reasoning as
 * CheckBlacklistOnOrderCreated — the flag must be visible on first load of
 * the confirmation screen.
 *
 * Skipped entirely for is_test orders (UC-25), in both directions: a test
 * order never gets flagged as a duplicate, and never counts as a prior
 * order when checking whether a later real order is a duplicate.
 */
class CheckDuplicateOnOrderCreated
{
    /**
     * How far back to look for a matching phone number.
     */
    private const LOOKBACK_DAYS = 30;

    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        if ($order->is_test) {
            return;
        }

        $isDuplicate = Order::where('business_id', $order->business_id)
            ->where('id', '!=', $order->id)
            ->where('customer_phone_hash', $order->customer_phone_hash)
            ->where('is_test', false)
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->exists();

        if ($isDuplicate) {
            $order->update(['is_duplicate_flagged' => true]);
        }
    }
}
