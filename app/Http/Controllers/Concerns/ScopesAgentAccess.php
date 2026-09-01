<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Restricts stores/products visible to a confirmation or fulfilment agent
 * to their AgentScope grants. A store-scope row grants the whole store; a
 * product-scope row grants that one product regardless of store. An agent
 * with no scope rows at all is unrestricted (scoped to the whole business),
 * matching the "empty scope = whole business" rule already used when
 * agent compensation is configured (see SyncsAgentCompensation).
 */
trait ScopesAgentAccess
{
    /**
     * True for roles whose visibility is restricted to their AgentScope
     * grants. Admins and super admins always see the whole business.
     */
    private function isScopedAgent(User $user): bool
    {
        return in_array($user->role, [UserRole::CONFIRMATION_AGENT, UserRole::FULFILMENT_AGENT], true);
    }

    /**
     * Store IDs explicitly granted to this agent, or null if the agent has
     * no store-scope rows (meaning: not restricted by store).
     *
     * @return array<int, int>|null
     */
    private function scopedStoreIds(User $user): ?array
    {
        if (! $this->isScopedAgent($user)) {
            return null;
        }

        $storeIds = $user->agentScopes()
            ->whereNotNull('store_id')
            ->pluck('store_id')
            ->all();

        return $storeIds !== [] ? $storeIds : null;
    }

    /**
     * Product IDs explicitly granted to this agent, or null if the agent has
     * no product-scope rows (meaning: not restricted by product).
     *
     * @return array<int, int>|null
     */
    private function scopedProductIds(User $user): ?array
    {
        if (! $this->isScopedAgent($user)) {
            return null;
        }

        $productIds = $user->agentScopes()
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->all();

        return $productIds !== [] ? $productIds : null;
    }

    /**
     * Restrict a product query to products directly scoped to the agent or
     * belonging to a store scoped to the agent. No-ops for admins or for an
     * agent with no scope rows at all (whole-business access).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyProductScope(Builder $query, User $user): Builder
    {
        $storeIds = $this->scopedStoreIds($user);
        $productIds = $this->scopedProductIds($user);

        if ($storeIds === null && $productIds === null) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($storeIds, $productIds) {
            if ($storeIds !== null) {
                $query->orWhereIn('store_id', $storeIds);
            }

            if ($productIds !== null) {
                $query->orWhereIn('id', $productIds);
            }
        });
    }

    /**
     * Restrict an orders query to only orders assigned to this agent.
     * No-ops for admins. Fulfilment agents are never assigned orders
     * (assigned_agent_id only ever refers to confirmation agents), so this
     * always excludes every order for them — the web orders list isn't
     * their workflow; that's the mobile scan-driven fulfilment queue.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyOrderAssignmentScope(Builder $query, User $user): Builder
    {
        if (! $this->isScopedAgent($user)) {
            return $query;
        }

        return $query->where('assigned_agent_id', $user->id);
    }
}
