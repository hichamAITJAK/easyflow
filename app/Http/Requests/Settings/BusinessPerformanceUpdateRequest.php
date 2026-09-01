<?php

namespace App\Http\Requests\Settings;

use App\Enums\PerformanceTargetPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updates the business-wide performance targets. The route is admin-gated
 * (`can:manage-users`); the business itself comes from the authenticated
 * user, never from input, so a request can only ever change its own
 * business's rows.
 */
class BusinessPerformanceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->business_id !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Both are percentages, so 100 is the ceiling — the same cap
            // UserValidationRules applies to per-agent rate overrides.
            'targets.confirmation_rate' => ['required', 'numeric', 'min:1', 'max:100'],
            'targets.delivery_success_rate' => ['required', 'numeric', 'min:1', 'max:100'],

            // Below ~1 every agent is judged on a handful of orders, which
            // is what this threshold exists to prevent.
            'min_orders_for_evaluation' => ['required', 'integer', 'min:1', 'max:1000'],

            // The rolling window each target is measured over. Agents can
            // carry their own period on the team page; this is the default
            // they inherit.
            'targets.confirmation_rate_period' => ['required', Rule::enum(PerformanceTargetPeriod::class)],
            'targets.delivery_success_rate_period' => ['required', Rule::enum(PerformanceTargetPeriod::class)],

            // Optional payout for meeting the target over that window.
            'targets.confirmation_rate_bonus' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'targets.delivery_success_rate_bonus' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'targets.confirmation_rate' => 'confirmation rate target',
            'targets.delivery_success_rate' => 'delivery success rate target',
            'min_orders_for_evaluation' => 'minimum orders for evaluation',
        ];
    }
}
