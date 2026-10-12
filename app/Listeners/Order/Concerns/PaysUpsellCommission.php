<?php

namespace App\Listeners\Order\Concerns;

use App\Enums\CommissionAmountType;
use App\Events\Commission\CommissionEarned;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\Order;

/**
 * The upsell half of a confirmation agent's commission, paid alongside
 * the per-order commission at the same trigger (confirmed or delivered):
 * a fixed amount per upsold order, or a percentage of what the upsell
 * added. Nothing for an order the agent didn't grow.
 */
trait PaysUpsellCommission
{
    /**
     * Records the upsell entry, if any, and returns its amount (0 when none).
     */
    private function payUpsellCommission(Order $order, CommissionRule $defaultRule): float
    {
        $upsell = (float) $order->upsell_amount;

        if ($upsell <= 0 || $defaultRule->upsell_amount_type === null || $defaultRule->upsell_amount === null) {
            return 0.0;
        }

        $amount = $defaultRule->upsell_amount_type === CommissionAmountType::PERCENTAGE
            ? round($upsell * ((float) $defaultRule->upsell_amount / 100), 2)
            : round((float) $defaultRule->upsell_amount, 2);

        if ($amount <= 0) {
            return 0.0;
        }

        $entry = CommissionLedgerEntry::create([
            'business_id' => $order->business_id,
            'user_id' => $order->assigned_agent_id,
            'order_id' => $order->id,
            'commission_rule_id' => $defaultRule->id,
            'amount' => $amount,
            'description' => 'Upsell +'.number_format($upsell, 2, '.', '').' MAD',
            'entry_type' => 'earned',
        ]);

        CommissionEarned::dispatch($entry);

        return $amount;
    }
}
