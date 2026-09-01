<?php

namespace App\Services\Operations\Performance;

use App\Enums\PerformanceMetric;
use App\Enums\UserRole;
use App\Models\DailyStatsSummary;
use App\Models\PerformanceTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Evaluates one agent's rolling performance against the PerformanceTargets
 * that apply to them (UC-22).
 *
 * Extracted so the nightly warning command and the mobile dashboard's
 * banner read from one implementation. Two copies of "is this agent below
 * target" would eventually disagree, and an agent seeing a clean dashboard
 * the morning after a warning notification is worse than either answer
 * alone.
 *
 * Reads exclusively from DailyStatsSummary — PRD: UC-22 "reads from the
 * same pre-computed stats used for dashboards; it does not run its own
 * live aggregation".
 */
class AgentPerformanceEvaluator
{
    /**
     * Every active target that applies to this agent, most specific first.
     *
     * An agent-specific target (user_id set) overrides the business-wide
     * default for the same metric — the same precedence rule the web
     * DashboardController applies on its own read side.
     *
     * @return array<int, PerformanceTarget>
     */
    public function targetsFor(User $agent): array
    {
        $targets = PerformanceTarget::query()
            ->where('business_id', $agent->business_id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $agent->id))
            ->get();

        return $targets
            ->groupBy(fn (PerformanceTarget $target) => $target->metric->value)
            ->map(fn ($forMetric) => $forMetric->firstWhere('user_id', $agent->id) ?? $forMetric->first())
            ->values()
            ->all();
    }

    /**
     * Every agent a given target applies to.
     *
     * An agent-specific target (user_id set) applies to that one agent. A
     * business-wide default (user_id null) applies to every active
     * confirmation agent in the business who has no row of their own for
     * that metric — the more specific row wins, the same precedence
     * targetsFor() and the dashboards apply on the read side.
     *
     * Lives here rather than on either command because both the nightly
     * warning check and the bonus awarder need the identical answer: two
     * copies would eventually disagree about who a target covers, and an
     * agent warned but not paid (or vice versa) is worse than either
     * outcome alone. Same reasoning as evaluate() itself.
     *
     * @return Collection<int, User>
     */
    public function agentsForTarget(PerformanceTarget $target): Collection
    {
        if ($target->user_id !== null) {
            return $target->user ? collect([$target->user]) : collect();
        }

        $agentSpecificUserIds = PerformanceTarget::where('business_id', $target->business_id)
            ->where('metric', $target->metric)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        return User::where('business_id', $target->business_id)
            ->where('role', UserRole::CONFIRMATION_AGENT)
            ->whereNotIn('id', $agentSpecificUserIds)
            ->get();
    }

    /**
     * Evaluate one target for one agent.
     *
     * Returns null when the agent hasn't handled enough orders in the
     * window to judge (min_orders_for_evaluation) — a rate computed from
     * three orders isn't evidence of anything, and warning on it would
     * teach agents to ignore the warning.
     *
     * @return array{metric: PerformanceMetric, actual_rate: float, target_rate: float, is_below: bool, numerator: int, denominator: int}|null
     */
    public function evaluate(User $agent, PerformanceTarget $target): ?array
    {
        $since = Carbon::today()->subDays($target->period->days() - 1);

        $rows = DailyStatsSummary::where('business_id', $target->business_id)
            ->where('agent_id', $agent->id)
            // Only the agent's own totals: the same orders are also
            // recorded on business-wide and per-store rows, so including
            // those would count them several times over.
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->where('stat_date', '>=', $since)
            ->get();

        [$numerator, $denominator] = match ($target->metric) {
            PerformanceMetric::CONFIRMATION_RATE => [$rows->sum('confirmed_count'), $rows->sum('orders_count')],
            PerformanceMetric::DELIVERY_SUCCESS_RATE => [$rows->sum('delivered_count'), $rows->sum('submitted_to_courier_count')],
        };

        if ($denominator < $target->min_orders_for_evaluation) {
            return null;
        }

        $actualRate = round(($numerator / $denominator) * 100, 2);
        $targetRate = (float) $target->target_percentage;

        return [
            'metric' => $target->metric,
            'actual_rate' => $actualRate,
            'target_rate' => $targetRate,
            'is_below' => $actualRate < $targetRate,
            'numerator' => (int) $numerator,
            'denominator' => (int) $denominator,
        ];
    }

    /**
     * The target this agent is judged on, whether or not they are meeting
     * it — the dashboard shows progress toward it either way, so unlike
     * worstShortfallFor() this does not filter to shortfalls.
     *
     * Null when no active target applies, or when the agent hasn't handled
     * enough orders in the window to be judged.
     *
     * @return array{metric: string, actual_rate: float, target_rate: float, is_below: bool, period_days: int, orders_to_target: int|null}|null
     */
    public function progressFor(User $agent): ?array
    {
        $results = [];

        foreach ($this->targetsFor($agent) as $target) {
            $result = $this->evaluate($agent, $target);

            if ($result !== null) {
                $results[] = $result + ['period_days' => $target->period->days()];
            }
        }

        if ($results === []) {
            return null;
        }

        // Confirmation rate first when both apply: it is the metric this
        // agent's own work moves, where delivery success also depends on
        // the courier.
        usort($results, fn ($a, $b) => ($a['metric'] === PerformanceMetric::CONFIRMATION_RATE ? 0 : 1)
            <=> ($b['metric'] === PerformanceMetric::CONFIRMATION_RATE ? 0 : 1));

        $primary = $results[0];

        return [
            'metric' => $primary['metric']->value,
            'actual_rate' => $primary['actual_rate'],
            'target_rate' => $primary['target_rate'],
            'is_below' => $primary['is_below'],
            'period_days' => $primary['period_days'],
            'orders_to_target' => $this->ordersToTarget(
                $primary['numerator'],
                $primary['denominator'],
                $primary['target_rate'],
            ),
        ];
    }

    /**
     * How many more orders the agent must convert consecutively to reach
     * the target, assuming every one of them lands. Null when they are
     * already at or above it.
     *
     * Solves (numerator + n) / (denominator + n) >= target for the
     * smallest whole n. Only meaningful for a target below 100%: at 100%
     * no number of further conversions can offset a past miss, so that
     * returns null rather than an impossible figure.
     *
     * Note this is a floor, not a forecast — it assumes a perfect run from
     * here, and it ignores the rolling window ageing older orders out.
     */
    private function ordersToTarget(int $numerator, int $denominator, float $targetRate): ?int
    {
        if ($targetRate >= 100.0) {
            return null;
        }

        $target = $targetRate / 100;

        if ($denominator > 0 && ($numerator / $denominator) >= $target) {
            return null;
        }

        $needed = (int) ceil((($target * $denominator) - $numerator) / (1 - $target));

        // Float ceil() can overshoot by one when the exact answer lands on
        // a whole number (5/10 toward 80% needs 15, not 16), so step back
        // while the smaller figure still clears the target.
        while ($needed > 1 && ($numerator + $needed - 1) >= $target * ($denominator + $needed - 1)) {
            $needed--;
        }

        return max($needed, 1);
    }

    /**
     * The single most pressing shortfall for this agent right now, or null
     * when they're meeting every target that applies (or haven't handled
     * enough orders to judge).
     *
     * One result, not a list: the dashboard shows a banner, and stacking
     * two warnings on a phone screen buries the numbers the agent came
     * for. Widest gap wins, since that's the one worth acting on first.
     *
     * @return array{metric: PerformanceMetric, actual_rate: float, target_rate: float, is_below: bool}|null
     */
    public function worstShortfallFor(User $agent): ?array
    {
        $shortfalls = [];

        foreach ($this->targetsFor($agent) as $target) {
            $result = $this->evaluate($agent, $target);

            if ($result !== null && $result['is_below']) {
                $shortfalls[] = $result;
            }
        }

        usort(
            $shortfalls,
            fn ($a, $b) => ($a['target_rate'] - $a['actual_rate']) <=> ($b['target_rate'] - $b['actual_rate'])
        );

        return end($shortfalls) ?: null;
    }
}
