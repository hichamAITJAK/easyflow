<?php

namespace Database\Factories;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1000, 9999),
            'status' => BusinessStatus::ACTIVE,
        ];
    }

    /**
     * Mirror production: every business starts on a free trial (see
     * BusinessController@store), so tenant-app feature tests pass the
     * EnsureActiveSubscription middleware. Use unsubscribed() to test the
     * blocked state.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Business $business) {
            if ($business->subscriptions()->doesntExist()) {
                app(SubscriptionService::class)->startTrial($business);
            }
        });
    }

    public function suspended(): static
    {
        return $this->state(['status' => BusinessStatus::SUSPENDED]);
    }

    /**
     * A business with no subscription at all — blocked by
     * EnsureActiveSubscription.
     */
    public function unsubscribed(): static
    {
        return $this->afterCreating(function (Business $business) {
            $business->subscriptions()->delete();
        });
    }
}
