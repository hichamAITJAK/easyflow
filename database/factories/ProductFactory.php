<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'store_id' => null,
            'external_product_id' => null,
            'name' => $this->faker->words(3, true),
            'sku' => strtoupper(Str::random(8)),
            'description' => $this->faker->paragraph(),
            'description_html' => null,
            'price' => $this->faker->randomFloat(2, 10, 500),
            'inventory_quantity' => $this->faker->numberBetween(0, 200),
            'public_url' => null,
            'thumbnail' => null,
            'tags' => null,
            'status' => true,
            'is_active' => true,
            'is_test' => false,
        ];
    }

    /** A manually-created product (no store). */
    public function manual(): static
    {
        return $this->state(['store_id' => null, 'external_product_id' => null]);
    }

    /** A product synced from a store. */
    public function synced(): static
    {
        return $this->state(function () {
            return [
                'store_id' => Store::factory(),
                'external_product_id' => (string) $this->faker->unique()->numberBetween(10000, 99999),
            ];
        });
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function test(): static
    {
        return $this->state(['is_test' => true]);
    }
}
