<?php

namespace App\Listeners\Order;

use App\Events\Order\ConfirmationStatusChanged;
use App\Events\Order\DeliveryStatusChanged;
use App\Models\Order;
use App\Models\OrderStatusEvent;

/**
 * The single writer of order_status_events (PRD section 7.3: no
 * controller, job, or webhook handler sets a status column directly, and
 * every transition is logged). Subscribes to both ConfirmationStatusChanged
 * and DeliveryStatusChanged — the audit row shape is identical either way,
 * so one listener class covers both rather than duplicating the write.
 *
 * Runs synchronously: the audit trail must be durable before the
 * triggering request completes, not deferred to a queue worker that could
 * fail independently of the status change it's supposed to record.
 */
class LogOrderStatusEvent
{
    public function handleConfirmationStatusChanged(ConfirmationStatusChanged $event): void
    {
        $this->log($event->order, $event->fromStatus->value, $event->toStatus->value, $event->actor?->id);
    }

    public function handleDeliveryStatusChanged(DeliveryStatusChanged $event): void
    {
        $this->log($event->order, $event->fromStatus?->value, $event->toStatus->value, $event->actor?->id);
    }

    private function log(Order $order, ?string $fromStatus, string $toStatus, ?int $actorId): void
    {
        OrderStatusEvent::create([
            'business_id' => $order->business_id,
            'order_id' => $order->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by_user_id' => $actorId,
        ]);
    }
}
