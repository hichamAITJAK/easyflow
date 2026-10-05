<?php

namespace App\Listeners\Order;

use App\Enums\CommissionAmountType;
use App\Enums\OrderConfirmationStatus;
use App\Events\Commission\CommissionEarned;
use App\Events\Order\OrderConfirmed;
use App\Listeners\Order\Concerns\IncrementsDailyStats;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\OrderItem;
use App\Models\Scopes\BusinessScope;

/**
 * Calculates and records the confirming agent's commission for an order
 * (UC-7: confirming "triggers commission calculation"). Runs synchronously
 * — this is a fast DB write, not worth deferring to a queue.
 *
 * One CommissionLedgerEntry per order line item, since an order can carry
 * several products with different override rates — resolving a single
 * rate for the whole order would silently misprice a mixed-product order.
 *
 * Rule precedence per item: a product-scoped override for that item's
 * product, else a store-scoped override for the order's store, else the
 * agent's default (store_id and product_id both null) rule. Only applies
 * when the resolved rule's trigger_status matches 'confirmed' — an agent
 * whose commission triggers on a different status (e.g. delivery) isn't
 * paid here; a future listener on the matching event handles that case.
 *
 * No-ops entirely for: is_test orders (UC-25), an unassigned order, an
 * agent on salary only (no per-order commission), an order with no line
 * items (a manually created order not yet itemized), and an order that
 * already has earned entries. That last guard matters because an order
 * can be confirmed more than once — updateStatus() only short-circuits
 * when the status is unchanged, so confirmed -> no_answer -> confirmed
 * fires OrderConfirmed again and would otherwise pay the agent twice.
 */
class CalculateAgentCommission
{
    use IncrementsDailyStats;

    public function handle(OrderConfirmed $event): void
    {
        $order = $event->order;

        if ($order->is_test || $order->assigned_agent_id === null) {
            return;
        }

        // Idempotency guard: never pay the same order twice. withoutGlobalScope
        // because BusinessScope keys off Auth::user(), which is absent when this
        // runs from a queued job or console command — an unscoped read here
        // would silently find no prior entries and pay the agent again.
        $alreadyCalculated = CommissionLedgerEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $order->business_id)
            ->where('order_id', $order->id)
            ->where('entry_type', 'earned')
            ->exists();

        if ($alreadyCalculated) {
            return;
        }

        $items = OrderItem::where('order_id', $order->id)->get();

        if ($items->isEmpty()) {
            return;
        }

        $rules = CommissionRule::where('business_id', $order->business_id)
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

            if ($rule->trigger_status !== OrderConfirmationStatus::CONFIRMED->value) {
                continue;
            }

            $amount = $this->resolveAmount($rule, $item);
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

        // Mirror the ledger into the pre-computed dashboard stats (the
        // "Agent commissions" tile and per-agent rows read commission_total,
        // never the ledger directly, per the PRD's dashboard rule).
        if ($orderCommission > 0) {
            $this->incrementDailyStatMoney($order, 'commission_total', $orderCommission);
        }
    }

    private function resolveAmount(CommissionRule $rule, OrderItem $item): float
    {
        $lineTotal = (float) $item->unit_price * $item->quantity;

        return match ($rule->amount_type) {
            CommissionAmountType::PERCENTAGE => round($lineTotal * ((float) $rule->amount / 100), 2),
            default => round((float) $rule->amount, 2),
        };
    }
}
