<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Customer>
 *
 * Note: name, phone, and address are encrypted at rest (cast to 'encrypted').
 * The factory stores plaintext values; Laravel's model cast layer handles
 * encryption on write and decryption on read.
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $phone = '06'.$this->faker->numerify('########');

        return [
            'business_id' => Business::factory(),
            'name' => $this->faker->name(),
            'phone' => $phone,
            'phone_hash' => Hash::make($phone),
            'address' => $this->faker->address(),
            'city' => $this->faker->city(),
            'orders_count' => 0,
            'delivered_orders_count' => 0,
            'returned_orders_count' => 0,
            'last_order_at' => null,
            'is_best_customer' => false,
            'is_blacklisted' => false,
        ];
    }

    public function bestCustomer(): static
    {
        return $this->state([
            'orders_count' => $this->faker->numberBetween(10, 50),
            'delivered_orders_count' => $this->faker->numberBetween(8, 40),
            'is_best_customer' => true,
            'last_order_at' => now()->subDays(rand(1, 30)),
        ]);
    }

    public function blacklisted(): static
    {
        return $this->state(['is_blacklisted' => true]);
    }
}
