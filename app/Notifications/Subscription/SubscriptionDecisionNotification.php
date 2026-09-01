<?php

namespace App\Notifications\Subscription;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the business's admins their payment request was approved or
 * rejected — closes the loop of the manual payment flow.
 */
class SubscriptionDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly bool $approved,
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
        $subscription = $this->subscription;

        if ($this->approved) {
            return (new MailMessage)
                ->subject(__('Your subscription is active'))
                ->line(__('Your payment was confirmed. Your subscription is active until :date.', [
                    'date' => $subscription->ends_at?->toDateString(),
                ]))
                ->action(__('Open the app'), route('dashboard'));
        }

        return (new MailMessage)
            ->subject(__('Your payment request was rejected'))
            ->line(__('We could not confirm your payment (reference :reference).', [
                'reference' => $subscription->reference_code,
            ]))
            ->line(__('Reason: :reason', ['reason' => $subscription->rejection_reason ?? '—']))
            ->line(__('You can submit a new request from the subscription page.'))
            ->action(__('Subscription'), route('subscription.blocked'));
    }
}
