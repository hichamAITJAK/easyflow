<?php

namespace App\Notifications\Subscription;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Renewal reminder sent to the business's admins at the configured
 * days-remaining thresholds.
 */
class SubscriptionExpiringSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $daysRemaining,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $isTrial = $this->subscription->plan_id === null;

        return (new MailMessage)
            ->subject($isTrial
                ? __('Your free trial ends in :days day(s)', ['days' => $this->daysRemaining])
                : __('Your subscription ends in :days day(s)', ['days' => $this->daysRemaining]))
            ->line($isTrial
                ? __('Your free trial ends on :date. Choose a plan to keep your access.', [
                    'date' => $this->subscription->ends_at?->toDateString(),
                ])
                : __('Your subscription ends on :date. Renew now to avoid any interruption.', [
                    'date' => $this->subscription->ends_at?->toDateString(),
                ]))
            ->action(__('View subscription'), route('subscription.edit'));
    }
}
