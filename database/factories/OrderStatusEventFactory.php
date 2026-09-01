<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusEvent>
 */
class OrderStatusEventFactory extends Factory
{
    protected $model = OrderStatusEvent::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'order_id' => Order::factory(),
            'from_status' => null,
            'to_status' => 'assigned',
            'changed_by_user_id' => null,
            'note' => null,
        ];
    }

    public function byAgent(User $agent): static
    {
        return $this->state(['changed_by_user_id' => $agent->id]);
    }

    public function withNote(string $note): static
    {
        return $this->state(['note' => $note]);
    }
}
