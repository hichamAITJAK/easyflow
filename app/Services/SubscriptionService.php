<?php

namespace App\Services;

use App\Enums\SubscriptionPaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Events\Subscription\PaymentRequestSubmitted;
use App\Events\Subscription\SubscriptionApproved;
use App\Events\Subscription\SubscriptionExpiringSoon;
use App\Events\Subscription\SubscriptionRejected;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * All subscription state transitions live here. There is no payment
 * gateway — the super admin's approve() click is the "webhook" of the
 * manual bank-transfer flow, and a future gateway integration would call
 * the same approve() from its webhook handler.
 */
class SubscriptionService
{
    /**
     * Start the free trial for a freshly onboarded business.
     */
    public function startTrial(Business $business): Subscription
    {
        return $business->subscriptions()->create([
            'plan_id' => null,
            'status' => SubscriptionStatus::TRIALING,
            'reference_code' => Subscription::nextReferenceCode(),
            'starts_at' => now(),
            'ends_at' => now()->addDays((int) config('subscription.trial_days')),
            'limits' => config('subscription.trial_limits'),
        ]);
    }

    /**
     * Record a payment claim from the block screen: the business picked a
     * plan, transferred the money (or will pay cash) and clicked the CTA.
     * The subscription stays blocked until the super admin approves.
     */
    public function submitPaymentRequest(
        Business $business,
        Plan $plan,
        SubscriptionPaymentMethod $paymentMethod,
        ?string $paymentReference = null,
        ?string $receiptPath = null,
    ): Subscription {
        $subscription = $business->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::PENDING,
            'reference_code' => Subscription::nextReferenceCode(),
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentReference,
            'receipt_path' => $receiptPath,
            'submitted_at' => now(),
        ]);

        PaymentRequestSubmitted::dispatch($subscription);

        return $subscription;
    }

    /**
     * Activate a pending subscription after the super admin verified the
     * transfer on the bank statement.
     *
     * A renewal approved during an still-running paid cycle starts when the
     * old cycle ends — approving early never costs the business days.
     */
    public function approve(Subscription $subscription, User $superAdmin, ?string $notes = null): Subscription
    {
        DB::transaction(function () use ($subscription, $superAdmin, $notes) {
            $plan = $subscription->plan;

            $currentPaidEnd = $subscription->business->subscriptions()
                ->where('id', '!=', $subscription->id)
                ->where('status', SubscriptionStatus::ACTIVE)
                ->whereNotNull('plan_id')
                ->where('ends_at', '>', now())
                ->max('ends_at');

            $startsAt = $currentPaidEnd ? Date::parse($currentPaidEnd) : now();

            $subscription->update([
                'status' => SubscriptionStatus::ACTIVE,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addDays($plan->duration_days),
                'limits' => $plan->limits,
                'paid_amount' => $subscription->paid_amount ?? $plan->price,
                'activated_by' => $superAdmin->id,
                'notes' => $notes ?? $subscription->notes,
            ]);

            // The trial is over the moment a paid cycle starts.
            $subscription->business->subscriptions()
                ->where('status', SubscriptionStatus::TRIALING)
                ->update(['status' => SubscriptionStatus::EXPIRED]);
        });

        SubscriptionApproved::dispatch($subscription);

        return $subscription;
    }

    /**
     * Refuse a payment request (no matching transfer, wrong amount, ...).
     * The business returns to the block screen and can resubmit.
     */
    public function reject(Subscription $subscription, string $reason): Subscription
    {
        $subscription->update([
            'status' => SubscriptionStatus::REJECTED,
            'rejection_reason' => $reason,
        ]);

        SubscriptionRejected::dispatch($subscription);

        return $subscription;
    }

    /**
     * Daily check: expire what is past due and fire renewal reminders at
     * the configured thresholds.
     */
    public function runDailyCheck(): void
    {
        // Trials end hard at ends_at.
        Subscription::query()
            ->where('status', SubscriptionStatus::TRIALING)
            ->where('ends_at', '<=', now())
            ->update(['status' => SubscriptionStatus::EXPIRED]);

        // Paid cycles flip to expired at ends_at — grantsAccess() still
        // honors the grace window, so this is bookkeeping plus the banner
        // trigger, not the actual cut-off.
        Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE)
            ->where('ends_at', '<=', now())
            ->update(['status' => SubscriptionStatus::EXPIRED]);

        // Renewal reminders. Runs once a day, so each threshold fires once.
        $thresholds = config('subscription.reminder_days', []);

        Subscription::query()
            ->usable()
            ->where('ends_at', '>', now())
            ->with('business')
            ->get()
            ->each(function (Subscription $subscription) use ($thresholds) {
                $daysRemaining = $subscription->daysRemaining();

                if (in_array($daysRemaining, $thresholds, true)) {
                    SubscriptionExpiringSoon::dispatch($subscription, $daysRemaining);
                }
            });
    }
}
