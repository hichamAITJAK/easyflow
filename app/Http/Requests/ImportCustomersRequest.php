<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Uploads a CSV of customers for the caller's own business. The route is
 * already admin-gated (`manage-users`); business scoping comes from the
 * authenticated user's business_id in the controller, never from input.
 */
class ImportCustomersRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:5120',
                // `mimes:csv,txt` alone is unreliable here: browsers report
                // a CSV as text/csv, application/vnd.ms-excel, or
                // application/octet-stream depending on the OS, so the
                // extension is the dependable signal.
                'extensions:csv,txt',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('Choose a CSV file to import.'),
            'file.extensions' => __('The file must be a CSV.'),
            'file.max' => __('The file may not be larger than 5 MB.'),
        ];
    }
}
