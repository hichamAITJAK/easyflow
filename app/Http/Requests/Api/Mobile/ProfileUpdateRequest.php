<?php

namespace App\Http\Requests\Api\Mobile;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mobile counterpart to Settings\ProfileUpdateRequest: same name/email/phone
 * rules (via the shared trait, so uniqueness and format never drift between
 * web and mobile), but deliberately no avatar fields — avatar editing isn't
 * supported from mobile yet (upload/preset picker stays a web-only flow for
 * now).
 */
class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($this->user()->id),
            'phone' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
