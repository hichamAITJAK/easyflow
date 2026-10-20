<?php

namespace App\Listeners\Order;

use App\Events\Commission\CommissionEarned;
use App\Events\Order\OrderDelivered;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\Scopes\BusinessScope;

/**
 * Pays a delivery guy his fixed amount per parcel once he marks it
 * delivered. Never for test orders, never twice for the same parcel.
 */
class CalculateDeliveryGuyCommission
{
    public function handle(OrderDelivered $event): void
    {
        $order = $event->order;

        if ($order->is_test || $order->delivery_guy_id === null) {
            return;
        }

        $rule = CommissionRule::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $order->business_id)
            ->where('user_id', $order->delivery_guy_id)
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->where('is_active', true)
            ->first();

        if ($rule === null || ! $rule->payment_mode->paysCommission() || $rule->amount === null) {
            return;
        }

        $alreadyPaid = CommissionLedgerEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $order->business_id)
            ->where('order_id', $order->id)
            ->where('user_id', $order->delivery_guy_id)
            ->where('entry_type', 'earned')
            ->exists();

        if ($alreadyPaid) {
            return;
        }

        $entry = CommissionLedgerEntry::create([
            'business_id' => $order->business_id,
            'user_id' => $order->delivery_guy_id,
            'order_id' => $order->id,
            'commission_rule_id' => $rule->id,
            'amount' => round((float) $rule->amount, 2),
            'entry_type' => 'earned',
            'description' => __('Parcel delivered'),
        ]);

        CommissionEarned::dispatch($entry);
    }
}
