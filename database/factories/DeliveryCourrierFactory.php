<?php

namespace Database\Factories;

use App\Models\DeliveryCourrier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryCourrier>
 */
class DeliveryCourrierFactory extends Factory
{
    protected $model = DeliveryCourrier::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'description' => null,
            'logo' => null,
        ];
    }
}
