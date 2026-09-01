<?php

namespace App\Http\Requests\Subscription;

use App\Enums\SubscriptionPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SubmitPaymentRequest extends FormRequest
{
    /**
     * Only owners/admins can claim a payment for the business.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-users');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'payment_method' => ['required', Rule::enum(SubscriptionPaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            // Optional on purpose: cash payers and WhatsApp-photo senders
            // must still be able to submit the claim.
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
