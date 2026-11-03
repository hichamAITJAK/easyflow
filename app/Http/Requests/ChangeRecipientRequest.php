<?php

namespace App\Http\Requests;

use App\Enums\Courier;
use App\Enums\OrderDeliveryStatus;
use App\Models\Order;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChangeRecipientRequest extends FormRequest
{
    /**
     * Only an Onessta parcel still on its way can change recipient — the
     * one courier whose API lets the client be edited after shipping. The
     * courier itself has the last word (refused / invoiced parcels).
     */
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order
            && $order->business_id === $this->user()->business_id
            && $order->courier_slug === Courier::ONESSTA->value
            && $order->courier_tracking_number !== null
            && $order->is_delivery_active
            && ! in_array($order->delivery_status, self::CLOSED, true);
    }

    /**
     * Still "active" for us (the parcel comes back), but past changing.
     */
    private const CLOSED = [
        OrderDeliveryStatus::REFUSED,
        OrderDeliveryStatus::RETURNED_IN_TRANSIT,
        OrderDeliveryStatus::RETURN_RECEIVED,
    ];

    /**
     * Onessta's own limits, so the courier never rejects what passes here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:30'],
            'customer_phone' => ['required', 'string', 'regex:/^\D*(\d\D*){10,15}$/'],
            'customer_address' => ['required', 'string', 'max:255'],
            'parcel_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_phone.regex' => __('The phone number must have between 10 and 15 digits.'),
        ];
    }
}
