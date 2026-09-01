<?php

namespace Database\Factories;

use App\Enums\DeliveryAccountStatus;
use App\Models\Business;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryAccount>
 */
class DeliveryAccountFactory extends Factory
{
    protected $model = DeliveryAccount::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'courier_id' => DeliveryCourrier::factory(),
            'collect_city_id' => null,
            'label' => $this->faker->words(2, true),
            'api_credentials' => $this->faker->uuid(),
            'webhook_secret' => null,
            'is_default' => false,
            'status' => DeliveryAccountStatus::ACTIVE,
        ];
    }
}
