<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'order_item_id' => null,
            'type' => 'adjustment',
            'quantity_change' => $this->faker->numberBetween(-10, 10),
            'note' => null,
        ];
    }
}
