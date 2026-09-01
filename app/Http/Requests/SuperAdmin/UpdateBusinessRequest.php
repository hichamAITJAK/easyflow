<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\Business;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage-platform');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Status is deliberately absent: it moves through the dedicated
     * updateStatus action so every transition goes down one path.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(Business::class, 'slug')->ignore($this->business()->id),
            ],
        ];
    }

    /**
     * The route-bound business being updated.
     *
     * `route()` is declared as `object|string` because a parameter may be a
     * raw string when no model binding applies; this route always resolves
     * a Business, so it is narrowed once here rather than at each use.
     */
    private function business(): Business
    {
        $business = $this->route('business');

        if (! $business instanceof Business) {
            throw new \LogicException('The business route parameter is not bound to a Business.');
        }

        return $business;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('The slug may only contain lowercase letters, numbers, and single hyphens.'),
        ];
    }
}
