<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    /**
     * The feature ceilings a plan can set, matching the keys read by
     * Subscription::limit() and seeded in config('subscription.trial_limits').
     * A missing/null value means unlimited.
     *
     * @var list<string>
     */
    public const LIMIT_KEYS = [
        'max_stores',
        'max_delivery_couriers',
        'max_confirmation_agents',
        'max_fulfilment_agents',
        'daily_orders',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('manage-platform');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Absent when creating, bound when updating — `route()` is declared
        // `object|string`, so the binding is narrowed rather than assumed.
        $plan = $this->route('plan');
        $planId = $plan instanceof Plan ? $plan->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(Plan::class, 'slug')->ignore($planId),
            ],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['required', 'boolean'],

            // Each limit is optional; leaving one blank means unlimited.
            // Unknown keys are rejected so a typo'd limit name can't be
            // stored and silently never enforced.
            'limits' => ['nullable', 'array:'.implode(',', self::LIMIT_KEYS)],
            'limits.*' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => __('The slug may only contain lowercase letters, numbers, and single hyphens.'),
        ];
    }

    /**
     * Drop blank limits so the stored JSON only carries real ceilings —
     * Subscription::limit() treats a missing key as unlimited, and writing
     * nulls would just be noise. A plan with no limits at all stores null.
     *
     * @return array<string, mixed>
     */
    public function planAttributes(): array
    {
        $validated = $this->validated();

        $limits = array_filter(
            $validated['limits'] ?? [],
            fn ($value): bool => $value !== null && $value !== '',
        );

        $validated['limits'] = $limits === [] ? null : array_map('intval', $limits);

        return $validated;
    }
}
