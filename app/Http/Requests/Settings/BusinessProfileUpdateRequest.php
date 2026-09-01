<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Updates the business's own identity details — the fields that appear on
 * invoices. Admin-gated at the route; the business comes from the
 * authenticated user, never from input.
 *
 * `slug` is deliberately absent: it identifies the business and changing it
 * would break anything already pointing at it, so it stays super-admin-only.
 */
class BusinessProfileUpdateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'ice' => ['nullable', 'string', 'max:50'],
            'rc' => ['nullable', 'string', 'max:50'],
            'if_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'max:2048'],
            // Sent by the form's remove control; the file itself is deleted
            // in the controller so the disk doesn't keep orphans.
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'if_number' => 'IF',
            'ice' => 'ICE',
            'rc' => 'RC',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.max' => __('The logo may not be larger than 2 MB.'),
            'logo.image' => __('The logo must be an image file.'),
        ];
    }
}
