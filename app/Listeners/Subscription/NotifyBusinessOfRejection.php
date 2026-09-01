<?php

namespace App\Listeners\Subscription;

use App\Events\Subscription\SubscriptionRejected;
use App\Notifications\Subscription\SubscriptionDecisionNotification;
use App\Support\BusinessAdmins;
use Illuminate\Support\Facades\Notification;

class NotifyBusinessOfRejection
{
    public function handle(SubscriptionRejected $event): void
    {
        Notification::send(
            BusinessAdmins::of($event->subscription->business),
            new SubscriptionDecisionNotification($event->subscription, approved: false),
        );
    }
}
