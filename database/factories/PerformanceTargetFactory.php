<?php

namespace Database\Factories;

use App\Enums\PerformanceMetric;
use App\Enums\PerformanceTargetPeriod;
use App\Models\Business;
use App\Models\PerformanceTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceTarget>
 */
class PerformanceTargetFactory extends Factory
{
    protected $model = PerformanceTarget::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'metric' => PerformanceMetric::CONFIRMATION_RATE->value,
            'target_percentage' => $this->faker->randomFloat(2, 70, 95),
            'period' => PerformanceTargetPeriod::MONTHLY->value,
            'min_orders_for_evaluation' => 10,
            'is_active' => true,
        ];
    }

    public function confirmationRate(float $target = 80.0): static
    {
        return $this->state([
            'metric' => PerformanceMetric::CONFIRMATION_RATE->value,
            'target_percentage' => $target,
        ]);
    }

    public function deliverySuccessRate(float $target = 75.0): static
    {
        return $this->state([
            'metric' => PerformanceMetric::DELIVERY_SUCCESS_RATE->value,
            'target_percentage' => $target,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
