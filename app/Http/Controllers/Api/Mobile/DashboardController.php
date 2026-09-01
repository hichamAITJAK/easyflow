<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Concerns\FiltersOrdersByBucket;
use App\Http\Controllers\Concerns\ScopesAgentAccess;
use App\Http\Controllers\Controller;
use App\Models\CommissionLedgerEntry;
use App\Models\DailyStatsSummary;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\Performance\AgentPerformanceEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile Confirmation agent navigator's Dashboard tab (PRD UC-23).
 *
 * Answers one question the agent opens the app for: how am I doing? Their
 * all-time totals, what work is still waiting, and what they've earned —
 * nothing about other agents, stores, or products, which are the admin
 * dashboard's questions rather than an agent's.
 *
 * Rates come from daily_stats_summary, never a live aggregate over orders
 * (PRD: "Dashboards never query the live orders table"). The queue counts
 * are the exception and deliberately so: "how many leads are left" is a
 * question about the queue's state right now, not a historical statistic,
 * and it reuses the same bucket counts the Leads screen itself shows so
 * the two can never disagree.
 */
class DashboardController extends Controller
{
    use FiltersOrdersByBucket;
    use ScopesAgentAccess;

    public function __construct(private readonly AgentPerformanceEvaluator $performance) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $totals = DailyStatsSummary::query()
            ->where('business_id', $user->business_id)
            ->where('agent_id', $user->id)
            // Rows are written per store and per product as well as per
            // agent; the store_id-null rows are the agent's own totals, so
            // taking all of them would count the same order several times.
            ->whereNull('store_id')
            ->whereNull('product_id')
            ->selectRaw('coalesce(sum(orders_count), 0) as handled')
            ->selectRaw('coalesce(sum(confirmed_count), 0) as confirmed')
            ->first();

        $handled = (int) ($totals->handled ?? 0);
        $confirmed = (int) ($totals->confirmed ?? 0);

        return response()->json([
            'totals' => [
                'handled' => $handled,
                'confirmed' => $confirmed,
                'confirmation_rate' => $this->rate($confirmed, $handled),
                'earnings' => $this->earningsFor($user->business_id, $user->id),
            ],
            'queue' => $this->queueCounts($request),
            'target' => $this->performance->progressFor($user),
            'warning' => $this->warningFor($user),
        ]);
    }

    /**
     * UC-22's performance warning, shown in-app rather than only as a
     * notification the agent may have dismissed days ago.
     *
     * Shares AgentPerformanceEvaluator with the nightly warning command,
     * so the banner and the notification can never disagree about whether
     * this agent is below target. Null when they're meeting every target
     * that applies, or haven't handled enough orders to be judged.
     *
     * @return array<string, mixed>|null
     */
    private function warningFor(User $user): ?array
    {
        $shortfall = $this->performance->worstShortfallFor($user);

        if ($shortfall === null) {
            return null;
        }

        return [
            'metric' => $shortfall['metric']->value,
            'actual_rate' => $shortfall['actual_rate'],
            'target_rate' => $shortfall['target_rate'],
        ];
    }

    /**
     * Bucket counts for the "what's left" links, straight from the same
     * helper the Leads screen's own counts endpoint uses.
     *
     * @return array<string, int>
     */
    private function queueCounts(Request $request): array
    {
        $query = $this->applyOrderAssignmentScope(Order::query(), $request->user())
            ->where('business_id', $request->user()->business_id);

        return $this->orderBucketCounts($query);
    }

    /**
     * What the agent has earned in total. Read from the commission ledger
     * rather than daily_stats_summary's commission_total: the ledger is
     * the record an agent is actually paid against, and the Commission tab
     * shows the same source — two numbers that disagree on the same screen
     * would be worse than one that lags.
     */
    private function earningsFor(int $businessId, int $userId): float
    {
        return (float) CommissionLedgerEntry::query()
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->sum('amount');
    }

    /**
     * Null rather than zero when nothing was handled — no orders means no
     * rate to report, which is a different fact from a rate of 0%.
     */
    private function rate(int $confirmed, int $handled): ?float
    {
        return $handled > 0 ? round(($confirmed / $handled) * 100, 1) : null;
    }
}
