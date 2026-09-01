<?php

namespace App\Events\Subscription;

use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by the daily subscription check when a usable subscription crosses
 * a renewal-reminder threshold (config subscription.reminder_days).
 */
class SubscriptionExpiringSoon
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $daysRemaining,
    ) {}
}
