<?php

namespace App\Console\Commands;

use App\Models\PerformanceTarget;
use App\Services\Operations\Performance\AgentPerformanceEvaluator;
use App\Services\Operations\Performance\PerformanceBonusAwarder;
use Illuminate\Console\Command;

/**
 * Awards performance bonuses to agents meeting a target that carries one.
 *
 * Runs daily and is safe to run repeatedly: PerformanceBonusAwarder snaps
 * each bonus to a fixed calendar window and a unique index enforces one
 * bonus per agent, per target, per window — so a second run on the same day,
 * or any run later in the same week/month, awards nothing new.
 *
 * Running daily rather than only at period end is deliberate: an agent who
 * meets a monthly target on the 10th is paid then rather than waiting, and
 * every later run that month is a no-op.
 */
class AwardPerformanceBonusesCommand extends Command
{
    protected $signature = 'agents:award-bonuses';

    protected $description = 'Award performance bonuses to agents who met a target carrying one';

    public function __construct(
        private readonly PerformanceBonusAwarder $awarder,
        private readonly AgentPerformanceEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $targets = PerformanceTarget::query()
            ->where('is_active', true)
            ->whereNotNull('bonus_amount')
            ->get();

        $awarded = 0;
        $total = 0.0;

        foreach ($targets as $target) {
            foreach ($this->evaluator->agentsForTarget($target) as $agent) {
                $entry = $this->awarder->award($agent, $target);

                if ($entry !== null) {
                    $awarded++;
                    $total += (float) $entry->amount;
                }
            }
        }

        $this->info("{$awarded} bonus(es) awarded, {$total} MAD total.");

        return self::SUCCESS;
    }
}
