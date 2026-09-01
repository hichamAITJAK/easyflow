<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ManualProductRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a manually-added product (store_id null — not synced from any
 * connected store), which is always fully customizable, variants included.
 */
class StoreProductRequest extends FormRequest
{
    use ManualProductRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->business_id !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->manualProductRules();
    }
}
