<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'plan_id' => null,
            'status' => SubscriptionStatus::TRIALING,
            'reference_code' => 'SUB-'.$this->faker->unique()->numberBetween(10000, 99999),
            'starts_at' => now(),
            'ends_at' => now()->addDays((int) config('subscription.trial_days')),
            'limits' => config('subscription.trial_limits'),
        ];
    }

    public function active(): static
    {
        return $this->state([
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::ACTIVE,
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->addDays(335),
            'limits' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state([
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::PENDING,
            'starts_at' => null,
            'ends_at' => null,
            'submitted_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::EXPIRED,
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDay(),
        ]);
    }
}
