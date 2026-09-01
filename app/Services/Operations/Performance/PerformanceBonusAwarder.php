<?php

namespace App\Services\Operations\Performance;

use App\Events\Commission\CommissionEarned;
use App\Models\CommissionLedgerEntry;
use App\Models\PerformanceTarget;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Awards a performance bonus when an agent meets a target that carries one.
 *
 * A bonus is earned over a window rather than for an order, so it lands in
 * commission_ledger_entries with entry_type 'bonus', a null order_id and
 * the period it covers. Everything downstream — invoice totals, the
 * earned/paid figures, the invoice PDF — sums `amount` over that table, so
 * a bonus flows into an agent's pay with no changes to any of them.
 *
 * NEVER PAID TWICE. Three independent guards, in order of authority:
 *
 *  1. A unique index on (business_id, user_id, performance_target_id,
 *     period_start) — the only guarantee that holds under concurrency, and
 *     the reason the insert is wrapped rather than trusted.
 *  2. An existence check before inserting, so the ordinary re-run path is
 *     a no-op rather than a caught exception.
 *  3. The period is snapped to a fixed calendar window (see periodWindow),
 *     so two runs on different days inside the same week or month compute
 *     the same period_start and therefore collide on guard 1.
 *
 * Without guard 3 the daily scheduler would award a fresh bonus every day
 * of a monthly window, because a rolling "last 30 days" start date moves.
 */
class PerformanceBonusAwarder
{
    public function __construct(private readonly AgentPerformanceEvaluator $evaluator) {}

    /**
     * Award the bonus on this target to this agent for the window ending
     * on $asOf, if they met it and haven't already been paid for it.
     *
     * @return CommissionLedgerEntry|null The new entry, or null when no
     *                                    bonus is due (target carries none,
     *                                    not enough orders, below target, or
     *                                    already awarded for this window).
     */
    public function award(User $agent, PerformanceTarget $target, ?Carbon $asOf = null): ?CommissionLedgerEntry
    {
        if ($target->bonus_amount === null || (float) $target->bonus_amount <= 0) {
            return null;
        }

        [$periodStart, $periodEnd] = $this->periodWindow($target, $asOf ?? Carbon::today());

        // Guard 2: the common path. Guard 1 still backs this up if two
        // workers reach the insert at once.
        if ($this->alreadyAwarded($agent, $target, $periodStart)) {
            return null;
        }

        $result = $this->evaluator->evaluate($agent, $target);

        // Null means too few orders to judge; is_below means not earned.
        if ($result === null || $result['is_below']) {
            return null;
        }

        try {
            $entry = CommissionLedgerEntry::create([
                'business_id' => $target->business_id,
                'user_id' => $agent->id,
                // A bonus belongs to a period, not an order.
                'order_id' => null,
                'commission_rule_id' => null,
                'performance_target_id' => $target->id,
                'amount' => $target->bonus_amount,
                'description' => $this->describe($target, $periodStart, $periodEnd),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'entry_type' => 'bonus',
            ]);
        } catch (QueryException $exception) {
            // Guard 1 fired: a concurrent run inserted the same bonus
            // between our check and this insert. Not an error — the bonus
            // exists, which is exactly the intended end state.
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }

        // Keeps daily_stats_summary.commission_total in step, same seam
        // per-order commission uses.
        CommissionEarned::dispatch($entry);

        return $entry;
    }

    /**
     * True when this agent already has a bonus row for this target and
     * window.
     */
    private function alreadyAwarded(User $agent, PerformanceTarget $target, Carbon $periodStart): bool
    {
        return CommissionLedgerEntry::where('business_id', $target->business_id)
            ->where('user_id', $agent->id)
            ->where('performance_target_id', $target->id)
            ->whereDate('period_start', $periodStart)
            ->exists();
    }

    /**
     * The fixed calendar window this bonus covers.
     *
     * Deliberately NOT the evaluator's rolling "last N days": a rolling
     * start date moves every day, so a daily scheduler would see a new
     * window each run and pay repeatedly. Snapping to the calendar means
     * every run inside the same week/month resolves to one period_start,
     * and the unique index turns repeat runs into no-ops.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodWindow(PerformanceTarget $target, Carbon $asOf): array
    {
        return match ($target->period->value) {
            'daily' => [$asOf->copy()->startOfDay(), $asOf->copy()->endOfDay()],
            'monthly' => [$asOf->copy()->startOfMonth(), $asOf->copy()->endOfMonth()],
            default => [$asOf->copy()->startOfWeek(), $asOf->copy()->endOfWeek()],
        };
    }

    private function describe(PerformanceTarget $target, Carbon $start, Carbon $end): string
    {
        $label = str_replace('_', ' ', $target->metric->value);

        return ucfirst($label).' bonus — '.$start->format('d M Y').' to '.$end->format('d M Y');
    }

    /**
     * Postgres reports 23505 and MySQL 1062 for a duplicate key; both
     * surface here as the driver-specific code.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}
