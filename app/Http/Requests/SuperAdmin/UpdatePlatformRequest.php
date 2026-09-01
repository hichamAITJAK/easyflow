<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-platform');
    }

    /**
     * `slug` is absent on purpose: it keys the EcomPlatform enum lookups
     * that resolve each platform's connection service, so it is not an
     * editable field.
     *
     * SVG is accepted because most brand logos ship as one, but it is not
     * covered by the `image` rule (which relies on getimagesize) — so the
     * mime types are listed explicitly instead.
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
