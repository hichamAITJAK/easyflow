<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\CustomerBlacklistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerBlacklistEntry>
 */
class CustomerBlacklistEntryFactory extends Factory
{
    protected $model = CustomerBlacklistEntry::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'phone_hash' => hash('sha256', $this->faker->unique()->phoneNumber()),
            'phone_encrypted' => $this->faker->phoneNumber(),
            'reason' => null,
            'notes' => null,
            'added_by_user_id' => null,
        ];
    }
}
