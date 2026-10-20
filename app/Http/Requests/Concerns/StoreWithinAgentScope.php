<?php

namespace App\Http\Requests\Concerns;

use App\Enums\UserRole;
use App\Models\Store;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The store a manual order belongs to: one of this business's stores, and
 * for a confirmation agent scoped to specific stores, one of those.
 */
trait StoreWithinAgentScope
{
    /**
     * @return array<int, mixed>
     */
    private function storeRules(): array
    {
        $user = $this->user();

        return [
            Rule::exists(Store::class, 'id')->where('business_id', $user->business_id),
            function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if ($user->role !== UserRole::CONFIRMATION_AGENT) {
                    return;
                }

                $allowed = $user->agentScopes()->whereNotNull('store_id')->pluck('store_id');

                // No store rows means the agent works the whole business.
                if ($allowed->isNotEmpty() && ! $allowed->contains((int) $value)) {
                    $fail(__('You can only create orders for the stores assigned to you.'));
                }
            },
        ];
    }
}
