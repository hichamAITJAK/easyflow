<?php

namespace App\Http\Requests\Stores;

use App\Models\Store;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deleting a store destroys its synced products (and their variants,
 * options and images), its commission overrides and its agent scopes, and
 * strands every past order by nulling their `store_id`. None of that is
 * recoverable, so the merchant has to type the store's name to confirm —
 * and it is enforced here, not only in the dialog, so the guard cannot be
 * skipped by posting directly.
 */
class DeleteStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->store()->business_id === $this->user()->business_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirmation' => [
                'required',
                'string',
                Rule::in([$this->store()->name]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => __('Type the store name to confirm.'),
            'confirmation.in' => __('That does not match the store name.'),
        ];
    }

    /**
     * The route-bound store being deleted.
     *
     * `route()` is declared `object|string` because a parameter may be a raw
     * string when no model binding applies; this route always resolves a
     * Store, so it is narrowed once here.
     */
    private function store(): Store
    {
        $store = $this->route('store');

        if (! $store instanceof Store) {
            throw new \LogicException('The store route parameter is not bound to a Store.');
        }

        return $store;
    }
}
