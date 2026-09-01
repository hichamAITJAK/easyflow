<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CommissionLedgerEntry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionLedgerEntry>
 */
class CommissionLedgerEntryFactory extends Factory
{
    protected $model = CommissionLedgerEntry::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'order_id' => Order::factory(),
            'commission_rule_id' => null,
            'invoice_id' => null,
            'amount' => $this->faker->randomFloat(2, 5, 100),
            'entry_type' => 'earned',
            'reversed_entry_id' => null,
        ];
    }

    public function reversal(CommissionLedgerEntry $original): static
    {
        return $this->state([
            'business_id' => $original->business_id,
            'user_id' => $original->user_id,
            'order_id' => $original->order_id,
            'commission_rule_id' => $original->commission_rule_id,
            'amount' => -abs((float) $original->amount),
            'entry_type' => 'reversal',
            'reversed_entry_id' => $original->id,
        ]);
    }
}
