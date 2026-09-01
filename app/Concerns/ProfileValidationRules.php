<?php

namespace App\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles. Role and
     * account status are deliberately absent — those are managed only by an
     * Owner/Manager from the team page, never by the user themselves here.
     *
     * @return array<string, array<int, ValidationRule|Unique|Closure|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'phone' => [
                'nullable',
                'string',
                'max:30',
                $userId === null
                    ? Rule::unique(User::class)
                    : Rule::unique(User::class)->ignore($userId),
            ],
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
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
