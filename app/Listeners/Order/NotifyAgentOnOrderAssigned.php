<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderAssigned;
use App\Notifications\OrderAssignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sends the UC-21 push notification to the newly assigned confirmation
 * agent. Queued — a push send is a network call to Expo's API and must
 * never add latency to the assign action (auto-assign on order creation,
 * or an admin's manual override) that triggered it.
 */
class NotifyAgentOnOrderAssigned implements ShouldQueue
{
    public function handle(OrderAssigned $event): void
    {
        $event->agent->notify(new OrderAssignedNotification($event->order));
    }
}
