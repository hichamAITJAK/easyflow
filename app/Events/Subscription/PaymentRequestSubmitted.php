<?php

namespace App\Events\Subscription;

use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a business claims it paid (bank transfer / cash) and submits
 * a payment request from the block screen. The super admin reviews it in
 * the super admin panel — this event is the "webhook" of the manual flow.
 */
class PaymentRequestSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Subscription $subscription) {}
}
