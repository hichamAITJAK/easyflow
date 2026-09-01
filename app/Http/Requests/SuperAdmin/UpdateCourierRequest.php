<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-platform');
    }

    /**
     * `slug` is absent on purpose: it keys the Courier enum lookups that
     * resolve each courier's operation service.
     *
     * SVG is listed explicitly because the `image` rule doesn't cover it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'logo' => [
                'nullable',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/svg+xml',
                'max:2048',
            ],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.mimetypes' => __('The logo must be an SVG, PNG, JPG, or WebP image.'),
            'logo.max' => __('The logo may not be larger than 2 MB.'),
        ];
    }
}
