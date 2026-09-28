<?php

namespace Database\Factories;

use App\Enums\BusinessStatus;
use App\Models\Business;
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

    public function suspended(): static
    {
        return $this->state(['status' => BusinessStatus::SUSPENDED]);
    }
}
