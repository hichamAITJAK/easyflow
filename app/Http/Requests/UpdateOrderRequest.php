<?php

namespace App\Http\Requests;

use App\Enums\OrderSource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request. An order
     * can no longer be edited once it has shipped — the courier already
     * has it, so its customer details and items are locked in.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order
            && $order->business_id === $this->user()->business_id
            && $order->delivery_status === null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source_platform' => ['nullable', new Enum(OrderSource::class)],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_address' => ['required', 'string', 'max:1000'],
            'customer_city' => ['nullable', 'string', 'max:255'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            // Free-text staff note ("call after 6pm", "confirm the colour").
            // Not client PII, but it is operator-entered text that ends up in
            // the same payloads, so it stays length-bounded like the rest.
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => [
                'nullable',
                Rule::exists(Product::class, 'id')->where('business_id', $this->user()->business_id),
            ],
            'items.*.product_variant_id' => [
                'nullable',
                Rule::exists(ProductVariant::class, 'id')->where('business_id', $this->user()->business_id),
            ],
            'items.*.product_name' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
