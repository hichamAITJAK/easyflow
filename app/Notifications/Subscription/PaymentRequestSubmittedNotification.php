<?php

namespace App\Notifications\Subscription;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the super admin a business claims it paid — the review trigger of
 * the manual payment flow.
 */
class PaymentRequestSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Subscription $subscription) {}

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
        $plan = $subscription->plan;

        return (new MailMessage)
            ->subject(__('New payment request — :business', ['business' => $subscription->business->name]))
            ->line(__(':business submitted a payment request.', ['business' => $subscription->business->name]))
            ->line(__('Plan: :plan (:price :currency)', [
                'plan' => $plan->name ?? '—',
                'price' => $plan->price ?? '—',
                'currency' => $plan->currency ?? '',
            ]))
            ->line(__('Reference: :reference', ['reference' => $subscription->reference_code]))
            ->line(__('Method: :method', ['method' => $subscription->payment_method->value ?? '—']))
            ->action(__('Review request'), route('super-admin.subscriptions.index'));
    }
}
