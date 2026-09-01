<?php

namespace App\Listeners\Subscription;

use App\Events\Subscription\SubscriptionExpiringSoon;
use App\Notifications\Subscription\SubscriptionExpiringSoonNotification;
use App\Support\BusinessAdmins;
use Illuminate\Support\Facades\Notification;

class NotifyBusinessOfExpiry
{
    public function handle(SubscriptionExpiringSoon $event): void
    {
        Notification::send(
            BusinessAdmins::of($event->subscription->business),
            new SubscriptionExpiringSoonNotification($event->subscription, $event->daysRemaining),
        );
    }
}
