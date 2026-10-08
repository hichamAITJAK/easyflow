<?php

namespace App\Http\Requests;

use App\Concerns\UserValidationRules;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    use UserValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage-users');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->userRules(),
            'password' => $this->newPasswordRules(),
            ...match ($this->input('role')) {
                UserRole::CONFIRMATION_AGENT->value => $this->confirmationAgentRules((int) $this->user()->business_id),
                UserRole::FULFILMENT_AGENT->value => $this->fulfilmentAgentRules((int) $this->user()->business_id),
                default => [],
            },
        ];
    }
}
