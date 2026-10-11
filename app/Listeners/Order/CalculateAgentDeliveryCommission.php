<?php

namespace App\Listeners\Order;

use App\Enums\CommissionAmountType;
use App\Enums\OrderDeliveryStatus;
use App\Events\Commission\CommissionEarned;
use App\Events\Order\OrderDelivered;
use App\Listeners\Order\Concerns\IncrementsDailyStats;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\OrderItem;
use App\Models\Scopes\BusinessScope;

/**
 * The "Order delivered" half of a confirmation agent's commission: lines
 * whose resolved rule triggers on delivered are paid here, once the
 * courier delivers. CalculateAgentCommission pays the "confirmed" ones at
 * confirmation; between them every line is paid exactly once, at the
 * moment its rule names.
 *
 * Same rule precedence per item (product override, then store override,
 * then the default rule) and the same conventions: never for test orders
 * or unassigned orders, never twice for the same order.
 */
class CalculateAgentDeliveryCommission
{
    use IncrementsDailyStats;

    public function handle(OrderDelivered $event): void
    {
        $order = $event->order;

        if ($order->is_test || $order->assigned_agent_id === null) {
            return;
        }

        // Idempotency: one payout per order for its delivered-trigger lines.
        $alreadyPaid = CommissionLedgerEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $order->business_id)
            ->where('order_id', $order->id)
            ->where('user_id', $order->assigned_agent_id)
            ->where('entry_type', 'earned')
            ->whereHas('commissionRule', fn ($query) => $query
                ->withoutGlobalScope(BusinessScope::class)
                ->where('trigger_status', OrderDeliveryStatus::DELIVERED->value))
            ->exists();

        if ($alreadyPaid) {
            return;
        }

        $items = OrderItem::where('order_id', $order->id)->get();

        if ($items->isEmpty()) {
            return;
        }

        $rules = CommissionRule::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $order->business_id)
            ->where('user_id', $order->assigned_agent_id)
            ->where('is_active', true)
            ->get();

        $defaultRule = $rules->first(fn (CommissionRule $rule) => $rule->store_id === null && $rule->product_id === null);

        if ($defaultRule === null || ! $defaultRule->payment_mode->paysCommission()) {
            return;
        }

        $storeRule = $order->store_id !== null
            ? $rules->first(fn (CommissionRule $rule) => $rule->store_id === $order->store_id)
            : null;

        $orderCommission = 0.0;

        foreach ($items as $item) {
            $productRule = $item->product_id !== null
                ? $rules->first(fn (CommissionRule $rule) => $rule->product_id === $item->product_id)
                : null;

            $rule = $productRule ?? $storeRule ?? $defaultRule;

            if ($rule->trigger_status !== OrderDeliveryStatus::DELIVERED->value) {
                continue;
            }

            $lineTotal = (float) $item->unit_price * $item->quantity;
            $amount = $rule->amount_type === CommissionAmountType::PERCENTAGE
                ? round($lineTotal * ((float) $rule->amount / 100), 2)
                : round((float) $rule->amount, 2);

            $orderCommission += $amount;

            $entry = CommissionLedgerEntry::create([
                'business_id' => $order->business_id,
                'user_id' => $order->assigned_agent_id,
                'order_id' => $order->id,
                'commission_rule_id' => $rule->id,
                'amount' => $amount,
                'entry_type' => 'earned',
            ]);

            CommissionEarned::dispatch($entry);
        }

        if ($orderCommission > 0) {
            $this->incrementDailyStatMoney($order, 'commission_total', $orderCommission);
        }
    }
}
