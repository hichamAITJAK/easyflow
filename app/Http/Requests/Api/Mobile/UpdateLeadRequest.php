<?php

namespace App\Http\Requests\Api\Mobile;

use App\Http\Controllers\Concerns\ScopesAgentAccess;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The mobile edit-order form (PRD UC-7).
 *
 * Mirrors the web UpdateOrderRequest's field rules so both clients accept
 * exactly the same order, and adds the agent-assignment scope the mobile
 * API applies everywhere else: an agent may only edit a lead assigned to
 * them.
 */
class UpdateLeadRequest extends FormRequest
{
    use ScopesAgentAccess;

    /**
     * An order can no longer be edited once the courier has it — the same
     * lock the web form applies, since a parcel already collected can't
     * follow a changed address.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');
        $user = $this->user();

        if (! $order instanceof Order || $order->business_id !== $user->business_id) {
            return false;
        }

        if ($this->isScopedAgent($user) && $order->assigned_agent_id !== $user->id) {
            return false;
        }

        return $order->delivery_status === null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_address' => ['required', 'string', 'max:1000'],
            'customer_city' => ['nullable', 'string', 'max:255'],
            'total_amount' => ['required', 'numeric', 'min:0'],
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
