<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\DeliveryAccount;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateShipmentRequest extends FormRequest
{
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
        return [
            // Either a courier account (with one of its cities) or one of
            // the business's own delivery guys, who brings his own city.
            'delivery_guy_id' => [
                'nullable',
                Rule::exists(User::class, 'id')
                    ->where('business_id', $this->user()->business_id)
                    ->where('role', UserRole::DELIVERY_GUY->value),
            ],
            'delivery_account_id' => [
                'required_without:delivery_guy_id',
                'nullable',
                Rule::exists(DeliveryAccount::class, 'id')->where('business_id', $this->user()->business_id),
            ],
            'city_id' => ['required_without:delivery_guy_id', 'nullable', 'integer', 'exists:delevery_courrier_cities,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_address' => ['required', 'string', 'max:1000'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'parcel_note' => ['nullable', 'string', 'max:255'],
            'parcel_nature' => ['nullable', 'string', 'max:255'],
            'parcel_open' => ['nullable', 'boolean'],
            'parcel_fragile' => ['nullable', 'boolean'],
            'parcel_replace' => ['nullable', 'boolean'],
        ];
    }
}
