<?php

namespace Database\Factories;

use App\Enums\StoreConnectionStatus;
use App\Models\Business;
use App\Models\EcommercePlatform;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'business_id' => Business::factory(),
            'platform_id' => EcommercePlatform::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'domain' => $this->faker->domainName(),
            'logo_url' => null,
            'description' => $this->faker->sentence(),
            'meta' => null,
            'external_store_id' => null,
            'api_credentials' => json_encode(['token' => Str::random(40)]),
            'webhook_secret' => Str::random(32),
            'connection_status' => StoreConnectionStatus::CONNECTED,
            'last_synced_at' => now()->subHour(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['connection_status' => StoreConnectionStatus::PENDING, 'last_synced_at' => null]);
    }

    public function failed(): static
    {
        return $this->state(['connection_status' => StoreConnectionStatus::FAILED]);
    }
}
