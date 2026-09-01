<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductOptionValue>
 */
class ProductOptionValueFactory extends Factory
{
    protected $model = ProductOptionValue::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'product_option_id' => ProductOption::factory(),
            'value' => $this->faker->word(),
            'position' => 0,
        ];
    }
}
