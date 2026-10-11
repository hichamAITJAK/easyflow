<?php

namespace App\Listeners\Order;

use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Events\Commission\CommissionEarned;
use App\Events\Commission\CommissionReversed;
use App\Events\Order\DeliveryStatusChanged;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionRule;
use App\Models\OrderStatusEvent;
use App\Models\Scopes\BusinessScope;
use App\Models\User;

/**
 * Pays a fulfilment agent their "per parcel" commission, at the moment
 * their rule says (see SyncsAgentCompensation):
 *
 * - ready_for_pickup ("shipped"): when they scan the parcel out, taken
 *   back when they undo that scan;
 * - delivered: when the courier reports the parcel delivered, paid to the
 *   agent whose scan staged it.
 *
 * Keyed off DeliveryStatusChanged
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
        if ($event->order->is_test) {
            return;
        }

        // Delivery comes from the courier (no actor): pay whoever staged it.
        if ($event->toStatus === OrderDeliveryStatus::DELIVERED) {
            $stagerId = $this->stagerOf($event);

            if ($stagerId !== null) {
                $this->pay($event, $stagerId, OrderDeliveryStatus::DELIVERED);
            }

            return;
        }

        $actor = $event->actor;

        if ($actor === null || $actor->role !== UserRole::FULFILMENT_AGENT) {
            return;
        }

        if ($event->toStatus === OrderDeliveryStatus::READY_FOR_PICKUP) {
            $this->pay($event, $actor->id, OrderDeliveryStatus::READY_FOR_PICKUP);
        } elseif ($event->fromStatus === OrderDeliveryStatus::READY_FOR_PICKUP && $event->toStatus === OrderDeliveryStatus::AWAITING_PICKUP) {
            $this->reverse($event, $actor->id);
        }
    }

    /**
     * Pay the agent if their rule triggers on $moment. A rule saved before
     * the trigger became selectable has none and pays at the scan, as it
     * always did.
     */
    private function pay(DeliveryStatusChanged $event, int $agentId, OrderDeliveryStatus $moment): void
    {
        $order = $event->order;

        $rule = CommissionRule::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $order->business_id)
            ->where('user_id', $agentId)
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->where('is_active', true)
            ->first();

        if ($rule === null || ! $rule->payment_mode->paysCommission() || $rule->amount === null) {
            return;
        }

        $trigger = $rule->trigger_status ?? OrderDeliveryStatus::READY_FOR_PICKUP->value;

        if ($trigger !== $moment->value) {
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
            'description' => $moment === OrderDeliveryStatus::DELIVERED
                ? __('Parcel delivered')
                : __('Parcel prepared'),
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
     * The fulfilment agent whose scan staged this parcel: the latest
     * ready_for_pickup transition made by one. An undone scan is followed
     * by a re-scan before the courier can collect, so the latest one is
     * the scan that actually shipped.
     */
    private function stagerOf(DeliveryStatusChanged $event): ?int
    {
        return OrderStatusEvent::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $event->order->business_id)
            ->where('order_id', $event->order->id)
            ->where('to_status', OrderDeliveryStatus::READY_FOR_PICKUP->value)
            ->whereIn('changed_by_user_id', User::withoutGlobalScopes()
                ->select('id')
                ->where('business_id', $event->order->business_id)
                ->where('role', UserRole::FULFILMENT_AGENT))
            ->latest('id')
            ->value('changed_by_user_id');
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
