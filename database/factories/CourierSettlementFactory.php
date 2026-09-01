<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CourierSettlement;
use App\Models\DeliveryAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourierSettlement>
 */
class CourierSettlementFactory extends Factory
{
    protected $model = CourierSettlement::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'delivery_account_id' => DeliveryAccount::factory(),
            'period_start' => $this->faker->date(),
            'period_end' => $this->faker->date(),
            'expected_amount' => $this->faker->randomFloat(2, 100, 5000),
            'actual_amount' => null,
            'difference_amount' => null,
            'status' => 'pending',
            'reconciled_at' => null,
            'reconciled_by' => null,
            'notes' => null,
        ];
    }
}
