<?php

namespace App\Concerns;

use App\Enums\CommissionAmountType;
use App\Enums\CommissionPaymentMode;
use App\Enums\PerformanceMetric;
use App\Enums\PerformanceTargetPeriod;
use App\Enums\SalaryPeriod;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Rules\UniqueUserPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

trait UserValidationRules
{
    /**
     * Get the validation rules used to validate managed users.
     *
     * @return array<string, array<int, ValidationRule|Enum|Unique|Closure|array<mixed>|string>>
     */
    protected function userRules(?int $userId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                $userId === null
                    ? Rule::unique(User::class)
                    : Rule::unique(User::class)->ignore($userId),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
                new UniqueUserPhone($userId),
            ],
            'role' => ['required', Rule::enum(UserRole::class)->only([
                UserRole::ADMIN,
                UserRole::CONFIRMATION_AGENT,
                UserRole::FULFILMENT_AGENT,
            ])],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'avatar_preset' => [
                'nullable',
                'string',
                'regex:/^[a-z0-9_]+\.png$/',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! is_file(public_path('assets/images/avatars/'.$value))) {
                        $fail(__('The selected avatar is invalid.'));
                    }
                },
            ],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get the validation rules used to validate a new user's password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function newPasswordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate an optional password change.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function optionalPasswordRules(): array
    {
        return ['nullable', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules for a confirmation agent's payment mode,
     * store/product scope, and performance targets.
     *
     * @return array<string, array<int, ValidationRule|Enum|Exists|Closure|array<mixed>|string>>
     */
    protected function confirmationAgentRules(int $businessId): array
    {
        return [
            'payment_mode' => ['required', Rule::enum(CommissionPaymentMode::class)],
            'salary_amount' => ['required_if:payment_mode,salary,salary_and_commission', 'nullable', 'numeric', 'min:0'],
            'salary_period' => ['required_if:payment_mode,salary,salary_and_commission', 'nullable', Rule::enum(SalaryPeriod::class)],
            'trigger_status' => ['required_if:payment_mode,commission,salary_and_commission', 'nullable', 'string'],
            'amount_type' => ['required_if:payment_mode,commission,salary_and_commission', 'nullable', Rule::enum(CommissionAmountType::class)],
            'amount' => [
                'required_if:payment_mode,commission,salary_and_commission',
                'nullable',
                'numeric',
                'min:0',
                $this->percentageCapRule('amount_type'),
            ],

            // Overrides only apply when the agent earns commission — a specific store or
            // product earns a different rate than the agent's default above.
            'overrides' => ['nullable', 'array'],
            'overrides.*.store_id' => [
                'nullable',
                'required_without:overrides.*.product_id',
                'prohibits:overrides.*.product_id',
                Rule::exists(Store::class, 'id')->where('business_id', $businessId),
            ],
            'overrides.*.product_id' => [
                'nullable',
                'required_without:overrides.*.store_id',
                Rule::exists(Product::class, 'id')->where('business_id', $businessId),
            ],
            'overrides.*.amount_type' => ['required', Rule::enum(CommissionAmountType::class)],
            'overrides.*.amount' => [
                'required',
                'numeric',
                'min:0',
                $this->percentageCapRule('overrides.*.amount_type'),
            ],

            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => [Rule::exists(Store::class, 'id')->where('business_id', $businessId)],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => [Rule::exists(Product::class, 'id')->where('business_id', $businessId)],

            'targets' => ['nullable', 'array'],
            'targets.*.metric' => ['required', Rule::enum(PerformanceMetric::class)],

            // Each target carries its own evaluation window and optional
            // bonus, so one agent can be judged monthly while another is
            // judged weekly. Omitted period falls back to the business-wide
            // row's window in SyncsAgentCompensation.
            'targets.*.period' => ['nullable', Rule::enum(PerformanceTargetPeriod::class)],
            'targets.*.bonus_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'targets.*.target_percentage' => [
                'required',
                'numeric',
                'min:0',
                function (string $attribute, mixed $value, Closure $fail) {
                    $parts = explode('.', $attribute);
                    $index = $parts[1] ?? null;
                    if ($index !== null && is_numeric($value)) {
                        $metric = request()->input("targets.{$index}.metric");
                        if (in_array($metric, [PerformanceMetric::CONFIRMATION_RATE->value, PerformanceMetric::DELIVERY_SUCCESS_RATE->value], true) && (float) $value > 100) {
                            $fail(__('The percentage target cannot exceed 100.'));
                        } elseif ((float) $value > 999.99) {
                            $fail(__('The target cannot exceed 999.99.'));
                        }
                    }
                },
            ],
        ];
    }

    /**
     * A commission amount can't exceed 100 when its sibling amount_type
     * field is 'percentage' — a fixed-MAD amount has no such ceiling, so the
     * cap only applies once the request says this rate is a percentage.
     * $amountTypeField may contain a `*` (e.g. 'overrides.*.amount_type'),
     * substituted with the same index as the amount field being validated.
     */
    protected function percentageCapRule(string $amountTypeField): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($amountTypeField) {
            if (! is_numeric($value)) {
                return;
            }

            $resolvedField = str_contains($amountTypeField, '*')
                ? preg_replace('/\*/', (string) explode('.', $attribute)[1], $amountTypeField, 1)
                : $amountTypeField;

            $amountType = request()->input($resolvedField);

            if ($amountType === CommissionAmountType::PERCENTAGE->value && (float) $value > 100) {
                $fail(__('The percentage rate cannot exceed 100.'));
            }
        };
    }

    /**
     * Get the validation rules for a fulfilment agent's payment mode:
     * salary, a fixed amount per prepared parcel, or both.
     *
     * @return array<string, array<int, ValidationRule|Enum|array<mixed>|string>>
     */
    protected function fulfilmentAgentRules(?int $businessId = null): array
    {
        return [
            // Which stores' parcels and products this agent handles; empty
            // means the whole business, same rule as confirmation agents.
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => [Rule::exists(Store::class, 'id')->where('business_id', $businessId)],
            'payment_mode' => ['required', Rule::enum(CommissionPaymentMode::class)],
            'salary_amount' => ['required_if:payment_mode,salary,salary_and_commission', 'nullable', 'numeric', 'min:0'],
            'salary_period' => ['required_if:payment_mode,salary,salary_and_commission', 'nullable', Rule::enum(SalaryPeriod::class)],
            'amount' => ['required_if:payment_mode,commission,salary_and_commission', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
