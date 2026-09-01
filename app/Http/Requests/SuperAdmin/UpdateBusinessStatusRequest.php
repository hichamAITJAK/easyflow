<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\BusinessStatus;
use App\Models\Business;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBusinessStatusRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(BusinessStatus::class)],
        ];
    }

    /**
     * A cancelled business is terminal: reopening one would silently restore
     * access for every user under it, so it has to be a deliberate, separate
     * decision rather than one click in a row menu.
     */
    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $business = $this->route('business');

                if ($business instanceof Business && $business->status === BusinessStatus::CANCELLED) {
                    $validator->errors()->add(
                        'status',
                        __('A cancelled business cannot change status.'),
                    );
                }
            },
        ];
    }

    public function status(): BusinessStatus
    {
        return BusinessStatus::from($this->string('status')->toString());
    }
}
