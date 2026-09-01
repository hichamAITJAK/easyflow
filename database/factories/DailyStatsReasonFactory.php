<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\DailyStatsReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyStatsReason>
 */
class DailyStatsReasonFactory extends Factory
{
    protected $model = DailyStatsReason::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'store_id' => null,
            'agent_id' => null,
            'stat_date' => $this->faker->date(),
            'reason_type' => 'cancellation',
            'reason_code' => 'other',
            'count' => $this->faker->numberBetween(0, 20),
        ];
    }
}
