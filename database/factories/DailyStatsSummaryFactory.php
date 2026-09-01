<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\DailyStatsSummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyStatsSummary>
 */
class DailyStatsSummaryFactory extends Factory
{
    protected $model = DailyStatsSummary::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'store_id' => null,
            'product_id' => null,
            'agent_id' => null,
            'stat_date' => $this->faker->date(),
            'orders_count' => 0,
            'assigned_count' => 0,
            'confirmed_count' => 0,
            'submitted_to_courier_count' => 0,
            'delivered_count' => 0,
            'returned_count' => 0,
            'cancelled_count' => 0,
            'refused_count' => 0,
            'confirmation_rate' => null,
            'delivery_success_rate' => null,
            'revenue_confirmed' => null,
            'commission_total' => null,
        ];
    }
}
