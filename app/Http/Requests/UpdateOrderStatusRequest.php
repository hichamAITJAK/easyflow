<?php

namespace App\Http\Requests;

use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->business_id !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * UC-9: cancelling an order requires a structured reason code; the
     * `other` code additionally requires a free-text note, never as a
     * substitute for a real code.
     *
     * The status is restricted to the manually-selectable subset, so a
     * forged request can't set a system-owned state (submitted_to_courier,
     * assigned, test_completed) that the pickers already hide.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCancelling = $this->input('confirmation_status') === OrderConfirmationStatus::CANCELLED->value;

        return [
            'confirmation_status' => ['required', Rule::enum(OrderConfirmationStatus::class)->only(OrderConfirmationStatus::manuallySelectable())],
            'cancellation_reason_code' => [
                Rule::requiredIf($isCancelling),
                Rule::excludeIf(! $isCancelling),
                Rule::enum(OrderCancelReason::class),
            ],
            'cancellation_note' => [
                Rule::requiredIf(fn () => $isCancelling && $this->input('cancellation_reason_code') === OrderCancelReason::OTHER->value),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
