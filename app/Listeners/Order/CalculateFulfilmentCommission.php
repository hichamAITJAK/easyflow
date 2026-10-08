<?php

namespace App\Listeners\Order;

use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Events\Commission\CommissionEarned;
use App\Events\Commission\CommissionReversed;
use App\Events\Order\DeliveryStatusChanged;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\Scopes\BusinessScope;

/**
 * Pays a fulfilment agent their "per parcel" commission when they scan a
 * parcel as ready for pickup, and takes it back when they undo that scan.
 *
 * Fulfilment agents' commission rules are stored with a fixed
 * trigger_status of ready_for_pickup (see SyncsAgentCompensation) — this
 * listener is what honours that trigger. Keyed off DeliveryStatusChanged
 * rather than ParcelReadyForPickup because only the former carries the
 * actor: the parcel belongs to the confirming agent (assigned_agent_id),
 * but the commission goes to whoever physically staged it.
 *
 * Same conventions as CalculateAgentCommission: never for test orders,
 * never twice for the same parcel, and corrections are appended as
 * reversal rows rather than deleting anything (PRD section 10).
 */
class CalculateFulfilmentCommission
{
    public function handle(DeliveryStatusChanged $event): void
    {
        $actor = $event->actor;

        if ($event->order->is_test || $actor === null || $actor->role !== UserRole::FULFILMENT_AGENT) {
            return;
        }

        if ($event->toStatus === OrderDeliveryStatus::READY_FOR_PICKUP) {
            $this->pay($event, $actor->id);
        } elseif ($event->fromStatus === OrderDeliveryStatus::READY_FOR_PICKUP && $event->toStatus === OrderDeliveryStatus::AWAITING_PICKUP) {
            $this->reverse($event, $actor->id);
        }
    }

    private function pay(DeliveryStatusChanged $event, int $agentId): void
    {
        $order = $event->order;

        $rule = CommissionRule::where('business_id', $order->business_id)
            ->where('user_id', $agentId)
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->where('is_active', true)
            ->first();

        if ($rule === null || ! $rule->payment_mode->paysCommission() || $rule->amount === null) {
            return;
        }

        if ($this->openEarnedEntry($order->business_id, $order->id, $agentId) !== null) {
            return;
        }

        $entry = CommissionLedgerEntry::create([
            'business_id' => $order->business_id,
            'user_id' => $agentId,
            'order_id' => $order->id,
            'commission_rule_id' => $rule->id,
            'amount' => round((float) $rule->amount, 2),
            'entry_type' => 'earned',
            'description' => __('Parcel prepared'),
        ]);

        CommissionEarned::dispatch($entry);
    }

    private function reverse(DeliveryStatusChanged $event, int $agentId): void
    {
        $order = $event->order;
        $earned = $this->openEarnedEntry($order->business_id, $order->id, $agentId);

        // Already invoiced: the money has left the ledger's control, so
        // the admin settles it by hand rather than a silent negative row.
        if ($earned === null || $earned->invoice_id !== null) {
            return;
        }

        $reversal = CommissionLedgerEntry::create([
            'business_id' => $order->business_id,
            'user_id' => $agentId,
            'order_id' => $order->id,
            'commission_rule_id' => $earned->commission_rule_id,
            'amount' => -round((float) $earned->amount, 2),
            'entry_type' => 'reversal',
            'reversed_entry_id' => $earned->id,
            'description' => __('Scan undone'),
        ]);

        CommissionReversed::dispatch($reversal);
    }

    /**
     * The agent's earned entry for this parcel that has not been reversed
     * yet. withoutGlobalScope: this may run from a queue with no
     * authenticated user, where BusinessScope would find nothing.
     */
    private function openEarnedEntry(int $businessId, int $orderId, int $agentId): ?CommissionLedgerEntry
    {
        return CommissionLedgerEntry::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('order_id', $orderId)
            ->where('user_id', $agentId)
            ->where('entry_type', 'earned')
            ->whereDoesntHave('reversalEntries')
            ->latest('id')
            ->first();
    }
}
