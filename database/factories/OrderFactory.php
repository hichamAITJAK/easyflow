<?php

namespace Database\Factories;

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 *
 * Note: customer_name, customer_phone, and customer_address are encrypted
 * at rest. The factory passes plaintext; the model cast layer handles
 * encryption on write and decryption on read.
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $phone = '06'.$this->faker->numerify('########');

        return [
            'reference' => strtoupper(Str::random(8)),
            'business_id' => Business::factory(),
            'store_id' => null,
            'external_order_id' => null,
            'source_platform' => 'manual',
            'assigned_agent_id' => null,

            'customer_name' => $this->faker->name(),
            'customer_phone' => $phone,
            'customer_phone_hash' => hash('sha256', $phone),
            'customer_address' => $this->faker->address(),
            'customer_city' => $this->faker->city(),
            'customer_ip_address' => null,

            'total_amount' => $this->faker->randomFloat(2, 50, 2000),
            'delivery_cost' => null,
            'returned_cost' => null,
            'refused_cost' => null,

            'confirmation_status' => OrderConfirmationStatus::NEW,
            'delivery_status' => null,
            'is_delivery_active' => false,

            'cancellation_reason_code' => null,
            'return_reason_code' => null,
            'notes' => null,
            'is_duplicate_flagged' => false,
            'is_blacklist_flagged' => false,
            'is_test' => false,

            'delivery_account_id' => null,
            'courier_tracking_number' => null,
            'courier_slug' => null,
            'delivery_driver_name' => null,
            'delivery_driver_phone' => null,
            'shipped_at' => null,

            'parcel_note' => null,
            'parcel_nature' => null,
            'parcel_open' => null,
            'parcel_fragile' => null,
            'parcel_replace' => null,
            'parcel_products' => null,

            'ready_for_pickup_at' => null,
            'return_received_at' => null,
            'ordered_at' => now()->subHours(rand(1, 72)),
            'raw_payload' => null,
        ];
    }

    /** Order synced from a connected store. */
    public function fromStore(?Store $store = null): static
    {
        return $this->state(function () use ($store) {
            return [
                'store_id' => $store->id ?? Store::factory(),
                'external_order_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
                'source_platform' => 'youcan',
            ];
        });
    }

    /** Assigned to a confirmation agent. */
    public function assigned(?User $agent = null): static
    {
        return $this->state([
            'assigned_agent_id' => $agent->id ?? User::factory()->confirmationAgent(),
            'confirmation_status' => OrderConfirmationStatus::ASSIGNED,
        ]);
    }

    /** Confirmed and ready to ship. */
    public function confirmed(): static
    {
        return $this->state([
            'confirmation_status' => OrderConfirmationStatus::CONFIRMED,
        ]);
    }

    /** Submitted to a courier, now in the delivery pipeline. */
    public function inTransit(): static
    {
        return $this->state([
            'confirmation_status' => OrderConfirmationStatus::SUBMITTED_TO_COURIER,
            'delivery_status' => OrderDeliveryStatus::IN_TRANSIT,
            'is_delivery_active' => true,
            'courier_tracking_number' => strtoupper(Str::random(12)),
            'courier_slug' => 'sendit',
            'shipped_at' => now()->subDay(),
        ]);
    }

    /** Successfully delivered. */
    public function delivered(): static
    {
        return $this->state([
            'confirmation_status' => OrderConfirmationStatus::SUBMITTED_TO_COURIER,
            'delivery_status' => OrderDeliveryStatus::DELIVERED,
            'is_delivery_active' => false,
            'shipped_at' => now()->subDays(3),
        ]);
    }

    /** Cancelled before shipment. */
    public function cancelled(): static
    {
        return $this->state([
            'confirmation_status' => OrderConfirmationStatus::CANCELLED,
            'cancellation_reason_code' => 'client_changed_mind',
        ]);
    }

    public function test(): static
    {
        return $this->state(['is_test' => true]);
    }
}
