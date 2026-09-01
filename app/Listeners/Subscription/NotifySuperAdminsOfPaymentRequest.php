<?php

namespace App\Listeners\Subscription;

use App\Enums\UserRole;
use App\Events\Subscription\PaymentRequestSubmitted;
use App\Models\User;
use App\Notifications\Subscription\PaymentRequestSubmittedNotification;
use Illuminate\Support\Facades\Notification;

class NotifySuperAdminsOfPaymentRequest
{
    public function handle(PaymentRequestSubmitted $event): void
    {
        $superAdmins = User::query()
            ->where('role', UserRole::SUPER_ADMIN)
            ->get();

        Notification::send(
            $superAdmins,
            new PaymentRequestSubmittedNotification($event->subscription),
        );
    }
}
