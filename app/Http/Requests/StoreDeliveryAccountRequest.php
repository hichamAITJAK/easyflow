<?php

namespace App\Http\Requests;

use App\Enums\Courier;
use App\Models\DeliveryCourrier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryAccountRequest extends FormRequest
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
        $courierSlug = DeliveryCourrier::find((int) $this->input('courier_id'))?->slug;

        return [
            'label' => ['required', 'string', 'max:255'],
            'courier_id' => ['required', 'exists:delivery_courriers,id'],
            'collect_city_id' => [
                'required',
                Rule::exists('delevery_courrier_cities', 'id')->where('courrier_id', $this->input('courier_id')),
            ],
            'public_key' => [Rule::requiredIf($courierSlug === Courier::SENDIT->value), 'string'],
            'secret_key' => [Rule::requiredIf($courierSlug === Courier::SENDIT->value), 'string'],
            'ozon_id' => [Rule::requiredIf($courierSlug === Courier::OZONEXPRESS->value), 'string'],
            'api_key' => [Rule::requiredIf(in_array($courierSlug, [Courier::OZONEXPRESS->value, Courier::COLIIX->value, Courier::FORCELOG->value, Courier::AMEEX->value], true)), 'string'],
            'client_id' => [Rule::requiredIf($courierSlug === Courier::COLIIX->value), 'string'],
            'api_id' => [Rule::requiredIf($courierSlug === Courier::AMEEX->value), 'string'],
        ];
    }

    /**
     * Custom validation messages, kept in scope for the one rule whose
     * default reads unhelpfully ("The selected collect city id is invalid")
     * without saying what to do about it.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collect_city_id.exists' => 'Select a city from the list for this courier.',
        ];
    }

    /**
     * Field names for validation messages, matching the labels shown on the
     * form exactly — so "The :attribute field is required" reads the same
     * noun the user is already looking at, instead of Laravel's raw
     * snake_case guess (e.g. "ozon id" instead of "OzonExpress customer ID").
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $courierSlug = DeliveryCourrier::find((int) $this->input('courier_id'))?->slug;

        return [
            'label' => 'account label',
            'collect_city_id' => 'collect city',
            'public_key' => 'Sendit public key',
            'secret_key' => 'Sendit secret key',
            'ozon_id' => 'OzonExpress customer ID',
            'client_id' => 'Coliix client ID',
            'api_id' => 'Ameex API ID',
            'api_key' => match ($courierSlug) {
                Courier::COLIIX->value => 'Coliix API key',
                Courier::FORCELOG->value => 'ForceLog API key',
                Courier::AMEEX->value => 'Ameex API key',
                default => 'OzonExpress API key',
            },
        ];
    }
}
