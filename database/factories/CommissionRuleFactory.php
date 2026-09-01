<?php

namespace Database\Factories;

use App\Enums\CommissionAmountType;
use App\Enums\CommissionPaymentMode;
use App\Enums\OrderConfirmationStatus;
use App\Enums\SalaryPeriod;
use App\Models\Business;
use App\Models\CommissionRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionRule>
 *
 * Defaults to a per-order commission rule (the most common case for
 * confirmation agents). Use the salary() state for fulfilment agents.
 */
class CommissionRuleFactory extends Factory
{
    protected $model = CommissionRule::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'payment_mode' => CommissionPaymentMode::COMMISSION,
            'store_id' => null,
            'product_id' => null,
            'trigger_status' => OrderConfirmationStatus::CONFIRMED->value,
            'amount_type' => CommissionAmountType::FIXED,
            'amount' => $this->faker->randomFloat(2, 5, 50),
            'salary_amount' => null,
            'salary_period' => null,
            'is_active' => true,
        ];
    }

    /** Fixed monthly salary — typically used for fulfilment agents. */
    public function salary(): static
    {
        return $this->state([
            'payment_mode' => CommissionPaymentMode::SALARY,
            'trigger_status' => null,
            'amount_type' => null,
            'amount' => null,
            'salary_amount' => $this->faker->randomFloat(2, 2000, 8000),
            'salary_period' => SalaryPeriod::MONTHLY,
        ]);
    }

    /** Percentage-based per-order commission. */
    public function percentage(): static
    {
        return $this->state([
            'payment_mode' => CommissionPaymentMode::COMMISSION,
            'amount_type' => CommissionAmountType::PERCENTAGE,
            'amount' => $this->faker->randomFloat(2, 1, 20),
            'salary_amount' => null,
            'salary_period' => null,
        ]);
    }

    /** Delivered-trigger commission (triggers on delivery rather than confirmation). */
    public function onDelivery(): static
    {
        return $this->state([
            'trigger_status' => 'delivered',
        ]);
    }
}
