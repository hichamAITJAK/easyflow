<?php

namespace App\Services\Operations\Customers;

use App\Models\Customer;
use App\Models\CustomerBlacklistEntry;
use App\Models\Order;
use App\Models\User;

/**
 * Customer business logic shared by every client (web, mobile). Methods
 * here take and return plain models/scalars/arrays only — never a Request,
 * never an HTTP response. Callers (controllers) are responsible for input
 * validation, authorization, and shaping the result for their transport.
 */
class CustomerService
{
    /**
     * Blacklist the client behind a given order: records a
     * CustomerBlacklistEntry for their phone (mirrors what
     * CheckBlacklistOnOrderCreated checks incoming orders against), flags
     * every one of their orders in this business (not just this one —
     * CheckBlacklistOnOrderCreated only flags orders placed *after* the
     * entry exists, so past orders from the same phone need the same
     * flip here to stay consistent), and keeps Customer.is_blacklisted in
     * sync the same way the listener does for a detected match.
     */
    public function blacklistOrderCustomer(Order $order, ?string $reason, ?string $notes, User $addedBy): CustomerBlacklistEntry
    {
        $entry = CustomerBlacklistEntry::create([
            'business_id' => $order->business_id,
            'phone_hash' => $order->customer_phone_hash,
            'phone_encrypted' => $order->customer_phone,
            'reason' => $reason,
            'notes' => $notes,
            'added_by_user_id' => $addedBy->id,
        ]);

        Order::where('business_id', $order->business_id)
            ->where('customer_phone_hash', $order->customer_phone_hash)
            ->where('is_blacklist_flagged', false)
            ->update(['is_blacklist_flagged' => true]);

        Customer::where('business_id', $order->business_id)
            ->where('phone_hash', $order->customer_phone_hash)
            ->update(['is_blacklisted' => true, 'is_best_customer' => false]);

        return $entry;
    }
}
