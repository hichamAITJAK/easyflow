<?php

namespace App\Listeners\Subscription;

use App\Events\Subscription\SubscriptionApproved;
use App\Notifications\Subscription\SubscriptionDecisionNotification;
use App\Support\BusinessAdmins;
use Illuminate\Support\Facades\Notification;

class NotifyBusinessOfApproval
{
    public function handle(SubscriptionApproved $event): void
    {
        Notification::send(
            BusinessAdmins::of($event->subscription->business),
            new SubscriptionDecisionNotification($event->subscription, approved: true),
        );
    }
}
