<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Seed the initial paid plan. Idempotent — safe to re-run.
     */
    public function run(): void
    {
        Plan::query()->updateOrCreate(
            ['slug' => 'standard-yearly'],
            [
                'name' => 'Standard',
                'price' => '2400.00',
                'currency' => 'MAD',
                'duration_days' => 365,
                'limits' => null, // unlimited
                'is_active' => true,
            ],
        );
    }
}
