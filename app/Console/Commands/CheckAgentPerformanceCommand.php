<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\PerformanceTarget;
use App\Models\User;
use App\Notifications\PerformanceWarningNotification;
use App\Services\Operations\Performance\AgentPerformanceEvaluator;
use Illuminate\Console\Command;

/**
 * UC-22: checks every active confirmation_rate/delivery_success_rate
 * PerformanceTarget against each applicable agent's rolling rate over the
 * target's own period window, and pushes a warning to the agent (and their
 * business's admins) when they're below it.
 *
 * Reads exclusively from DailyStatsSummary (PRD: "reads from the same
 * pre-computed stats used for dashboards — it does not run its own live
 * aggregation") — no query against the orders table here.
 */
class CheckAgentPerformanceCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agents:check-performance';

    /**
     * @var string
     */
    protected $description = 'Warn confirmation agents (and their admins) whose rolling performance has dropped below their target';

    public function __construct(private readonly AgentPerformanceEvaluator $evaluator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $targets = PerformanceTarget::query()
            ->where('is_active', true)
            ->get();

        $warned = 0;

        foreach ($targets as $target) {
            foreach ($this->evaluator->agentsForTarget($target) as $agent) {
                if ($this->checkAgentAgainstTarget($agent, $target)) {
                    $warned++;
                }
            }
        }

        $this->info("{$warned} performance warning(s) sent.");

        return self::SUCCESS;
    }

    /**
     * Sums the agent's DailyStatsSummary rows over the target's period
     * window and compares the resulting rate against the target. Returns
     * whether a warning was sent (skipped below min_orders_for_evaluation,
     * or if the rate already meets the target).
     */
    private function checkAgentAgainstTarget(User $agent, PerformanceTarget $target): bool
    {
        // Shared with the mobile dashboard's warning banner, so a warned
        // agent and their own dashboard can never disagree about whether
        // they're below target.
        $result = $this->evaluator->evaluate($agent, $target);

        if ($result === null || ! $result['is_below']) {
            return false;
        }

        $actualRate = $result['actual_rate'];

        $agent->notify(new PerformanceWarningNotification(
            $agent,
            $target->metric,
            $actualRate,
            (float) $target->target_percentage,
            forAgent: true,
        ));

        $admins = User::where('business_id', $target->business_id)
            ->where('role', UserRole::ADMIN)
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new PerformanceWarningNotification(
                $agent,
                $target->metric,
                $actualRate,
                (float) $target->target_percentage,
                forAgent: false,
            ));
        }

        return true;
    }
}
