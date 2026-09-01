<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\PerformanceTargetPeriod;
use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\CommissionRule;
use App\Models\PerformanceTarget;
use App\Models\User;

/**
 * Persists a confirmation/fulfilment agent's store & product scope,
 * payment mode (salary or commission), and performance targets from the
 * validated request data — shared by UserController's store() and update()
 * so both stay in lockstep with the same sync rules.
 */
trait SyncsAgentCompensation
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAgentCompensation(User $user, array $data): void
    {
        if ($user->role === UserRole::CONFIRMATION_AGENT) {
            $this->syncAgentScopes($user, $data['store_ids'] ?? [], $data['product_ids'] ?? []);
            $this->syncPerformanceTargets($user, $data['targets'] ?? []);
        } else {
            $user->agentScopes()->delete();
            $user->performanceTargets()->delete();
        }

        if (in_array($user->role, [UserRole::CONFIRMATION_AGENT, UserRole::FULFILMENT_AGENT], true)) {
            $this->syncCommissionRule($user, $data);
        } else {
            $user->commissionRules()->delete();
        }
    }

    /**
     * Replace this agent's store/product scope with two independent lists:
     * one row per selected store (product_id null) and one row per selected
     * product (store_id null). An empty pair of lists means the agent is
     * scoped to the whole business.
     *
     * @param  array<int, int>  $storeIds
     * @param  array<int, int>  $productIds
     */
    private function syncAgentScopes(User $user, array $storeIds, array $productIds): void
    {
        $user->agentScopes()->delete();

        $rows = [
            ...array_map(fn (int $storeId) => [
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'store_id' => $storeId,
                'product_id' => null,
            ], $storeIds),
            ...array_map(fn (int $productId) => [
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'store_id' => null,
                'product_id' => $productId,
            ], $productIds),
        ];

        if ($rows !== []) {
            AgentScope::insert($rows);
        }
    }

    /**
     * Replace this agent's performance targets — one row per submitted
     * metric. Metrics not present in $targets are removed (an unchecked
     * card means the target no longer applies).
     *
     * Each target carries its own evaluation window, so one agent can be
     * judged monthly while another is judged weekly. A target submitted
     * without a period inherits the business-wide row's window rather than
     * a hardcoded default, so an agent override doesn't silently change
     * how long they're measured over.
     *
     * @param  array<int, array{metric: string, target_percentage: float, period?: string|null, bonus_amount?: float|string|null}>  $targets
     */
    private function syncPerformanceTargets(User $user, array $targets): void
    {
        $user->performanceTargets()->delete();

        if ($targets === []) {
            return;
        }

        $businessPeriods = PerformanceTarget::where('business_id', $user->business_id)
            ->whereNull('user_id')
            ->pluck('period', 'metric')
            ->map(fn ($period) => $period instanceof PerformanceTargetPeriod ? $period->value : $period);

        foreach ($targets as $target) {
            $metric = $target['metric'];

            PerformanceTarget::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'metric' => $metric,
                'target_percentage' => $target['target_percentage'],
                // Blank clears the bonus rather than storing 0, which would
                // read as "meeting this target pays nothing" instead of
                // "this target has no bonus".
                'bonus_amount' => ($target['bonus_amount'] ?? null) !== null && $target['bonus_amount'] !== ''
                    ? $target['bonus_amount']
                    : null,
                'period' => $target['period']
                    ?? $businessPeriods[$metric]
                    ?? config('performance.default_period'),
            ]);
        }
    }

    /**
     * Create or update this agent's default commission rule (store_id and
     * product_id both null) from the submitted payment mode, and replace
     * their store/product overrides — rows that pay a different rate for a
     * specific store or product, on top of the default. Fulfilment agents'
     * "per parcel" mode is stored as payment_mode = commission with a fixed
     * trigger_status of ready_for_pickup (the fulfilment scan event), never
     * user-selectable, and never has overrides.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncCommissionRule(User $user, array $data): void
    {
        $isFulfilment = $user->role === UserRole::FULFILMENT_AGENT;
        $isCommission = $data['payment_mode'] === 'commission';

        CommissionRule::updateOrCreate(
            ['business_id' => $user->business_id, 'user_id' => $user->id, 'store_id' => null, 'product_id' => null],
            [
                'payment_mode' => $data['payment_mode'],
                'salary_amount' => $data['salary_amount'] ?? null,
                'salary_period' => $data['salary_period'] ?? null,
                'trigger_status' => $isCommission
                    ? ($isFulfilment ? 'ready_for_pickup' : ($data['trigger_status'] ?? null))
                    : null,
                'amount_type' => $isCommission
                    ? ($isFulfilment ? 'fixed' : ($data['amount_type'] ?? null))
                    : null,
                'amount' => $isCommission ? ($data['amount'] ?? null) : null,
                'is_active' => true,
            ],
        );

        $this->syncCommissionOverrides($user, $isCommission && ! $isFulfilment ? ($data['overrides'] ?? []) : []);
    }

    /**
     * Replace this agent's store/product commission overrides — one row per
     * submitted override, each scoped to exactly one store or one product
     * (never both, never neither; enforced in validation). Cleared entirely
     * when the agent isn't in commission mode, since a per-store/product
     * rate only means something on top of a commission-based default.
     *
     * There's no DB-level uniqueness on (user_id, store_id, product_id) —
     * MySQL treats every NULL as distinct in a unique index, so it wouldn't
     * actually catch two rows for the same store (product_id NULL on both).
     * De-duplication happens here instead: if the same store or product was
     * submitted more than once, the last one submitted wins.
     *
     * @param  array<int, array{store_id?: int, product_id?: int, amount_type: string, amount: float}>  $overrides
     */
    private function syncCommissionOverrides(User $user, array $overrides): void
    {
        CommissionRule::where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNotNull('store_id')->orWhereNotNull('product_id'))
            ->delete();

        $deduped = collect($overrides)
            ->keyBy(fn (array $override) => ($override['store_id'] ?? 'null').':'.($override['product_id'] ?? 'null'));

        foreach ($deduped as $override) {
            CommissionRule::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'payment_mode' => 'commission',
                'store_id' => $override['store_id'] ?? null,
                'product_id' => $override['product_id'] ?? null,
                'trigger_status' => null,
                'amount_type' => $override['amount_type'],
                'amount' => $override['amount'],
                'is_active' => true,
            ]);
        }
    }
}
