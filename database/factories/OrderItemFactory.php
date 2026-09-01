<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'order_id' => Order::factory(),
            'product_id' => null,
            'product_variant_id' => null,
            'product_name_snapshot' => $this->faker->words(3, true),
            'sku_snapshot' => null,
            'quantity' => $this->faker->numberBetween(1, 5),
            'unit_price' => $this->faker->randomFloat(2, 10, 500),
        ];
    }

    /** Attach a real product snapshot. */
    public function forProduct(Product $product): static
    {
        return $this->state([
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'unit_price' => $product->price ?? $this->faker->randomFloat(2, 10, 500),
        ]);
    }
}
