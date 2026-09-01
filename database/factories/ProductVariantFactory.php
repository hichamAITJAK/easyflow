<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'product_id' => Product::factory(),
            'external_variant_id' => null,
            'sku' => strtoupper(Str::random(10)),
            'image' => null,
            'price' => $this->faker->randomFloat(2, 10, 500),
            'inventory_quantity' => $this->faker->numberBetween(0, 100),
            'is_available' => true,
            'position' => $this->faker->numberBetween(1, 10),
        ];
    }

    public function synced(): static
    {
        return $this->state([
            'external_variant_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(['is_available' => false, 'inventory_quantity' => 0]);
    }
}
