<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => 'Standard',
            'slug' => 'standard-yearly-'.$this->faker->unique()->numberBetween(1000, 9999),
            'price' => '2400.00',
            'currency' => 'MAD',
            'duration_days' => 365,
            'limits' => null,
            'is_active' => true,
        ];
    }
}
