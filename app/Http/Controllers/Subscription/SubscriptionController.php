<?php

namespace App\Http\Controllers\Subscription;

use App\Enums\SubscriptionPaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\SubmitPaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    /**
     * The block screen: trial/subscription over, pick a plan, pay by bank
     * transfer or cash, submit the claim, wait for confirmation.
     */
    public function blocked(Request $request): Response|RedirectResponse
    {
        $business = $request->user()->business;

        // This page doubles as the renewal page: reachable when blocked,
        // in the grace window, or close enough to the end to renew early.
        $usable = $business->usableSubscription();

        if ($usable !== null && ! $usable->isInGracePeriod() && $usable->daysRemaining() > 15) {
            return redirect()->route('dashboard');
        }

        $current = $business->currentSubscription;

        return Inertia::render('subscription/blocked', [
            'isBlocked' => $usable === null,
            'currentSubscription' => $current ? $this->presentSubscription($current->loadMissing('plan:id,name')) : null,
            'plans' => Plan::query()->where('is_active', true)->orderBy('price')->get(),
            'bank' => config('subscription.bank'),
            'contactWhatsapp' => config('subscription.contact_whatsapp'),
            'canSubmit' => $request->user()->can('manage-users'),
            'nextReferenceCode' => Subscription::nextReferenceCode(),
        ]);
    }

    /**
     * "J'ai payé" — record the payment claim and notify the super admin.
     */
    public function store(SubmitPaymentRequest $request, SubscriptionService $subscriptions): RedirectResponse
    {
        $business = $request->user()->business;

        if ($business->subscriptions()->where('status', SubscriptionStatus::PENDING)->exists()) {
            return back()->withErrors([
                'plan_id' => __('A payment request is already waiting for review.'),
            ]);
        }

        $receiptPath = $request->hasFile('receipt')
            ? ($request->file('receipt')->store('subscription-receipts', 'public') ?: null)
            : null;

        $subscriptions->submitPaymentRequest(
            $business,
            Plan::findOrFail($request->integer('plan_id')),
            SubscriptionPaymentMethod::from($request->string('payment_method')->value()),
            $request->string('payment_reference')->value() ?: null,
            $receiptPath,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Payment request submitted. We will confirm it shortly.'),
        ]);

        return redirect()->route('subscription.blocked');
    }

    /**
     * Settings > Subscription: current plan, days remaining, and the
     * payment request history.
     */
    public function edit(Request $request): Response
    {
        $business = $request->user()->business;

        $current = $business->usableSubscription() ?? $business->currentSubscription;

        $history = $business->subscriptions()
            ->with('plan:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Subscription $subscription) => $this->presentSubscription($subscription));

        return Inertia::render('settings/subscription', [
            'subscription' => $current ? $this->presentSubscription($current->loadMissing('plan:id,name')) : null,
            'history' => $history,
            'trialDays' => (int) config('subscription.trial_days'),
        ]);
    }

    /**
     * Shape a subscription row for the frontend.
     *
     * @return array<string, mixed>
     */
    protected function presentSubscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'planName' => $subscription->plan->name ?? __('Free trial'),
            'isTrial' => $subscription->plan_id === null,
            'status' => $subscription->status->value,
            'referenceCode' => $subscription->reference_code,
            'startsAt' => $subscription->starts_at?->toDateString(),
            'endsAt' => $subscription->ends_at?->toDateString(),
            'submittedAt' => $subscription->submitted_at?->toDateString(),
            'daysRemaining' => $subscription->daysRemaining(),
            'totalDays' => $subscription->starts_at && $subscription->ends_at
                ? (int) ceil($subscription->starts_at->diffInDays($subscription->ends_at, true))
                : null,
            'inGracePeriod' => $subscription->isInGracePeriod(),
            'graceEndsAt' => $subscription->ends_at && $subscription->plan_id
                ? $subscription->graceEndsAt()->toDateString()
                : null,
            'paidAmount' => $subscription->paid_amount,
            'paymentMethod' => $subscription->payment_method?->value,
            'paymentReference' => $subscription->payment_reference,
            'rejectionReason' => $subscription->rejection_reason,
        ];
    }
}
