<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Notifications\Notification;

/**
 * UC-21: push notification to a confirmation agent the moment an order is
 * assigned to them (auto-assign or manual override, see OrderAssigned's own
 * docblock) — so they don't need to keep the app open or poll for updates.
 */
class OrderAssignedNotification extends Notification
{
    public function __construct(private readonly Order $order) {}

    /**
     * @return array<int, class-string>
     */
    public function via(User $notifiable): array
    {
        return [ExpoPushChannel::class];
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toExpoPush(User $notifiable): array
    {
        return [
            'title' => 'New order assigned',
            'body' => $this->order->reference
                ? "Order {$this->order->reference} needs confirmation."
                : 'A new order needs confirmation.',
            'data' => [
                'type' => 'order_assigned',
                'order_id' => $this->order->id,
            ],
        ];
    }
}
