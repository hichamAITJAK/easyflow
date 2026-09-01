<?php

namespace Database\Factories;

use App\Models\DeleveryCourrierCity;
use App\Models\DeliveryCourrier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeleveryCourrierCity>
 */
class DeleveryCourrierCityFactory extends Factory
{
    protected $model = DeleveryCourrierCity::class;

    public function definition(): array
    {
        return [
            'courrier_id' => DeliveryCourrier::factory(),
            'external_courrier_id' => null,
            'name' => $this->faker->city(),
            'arabic_name' => null,
        ];
    }
}
