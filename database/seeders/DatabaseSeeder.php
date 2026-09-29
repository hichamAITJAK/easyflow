<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Production-safe: everything called here is reference data the platform
 * cannot run without, and none of it touches a factory or Faker. Demo
 * data lives in FakerSeeder, which is for development machines only.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);
        $this->call(EcommercePlatformSeeder::class);
        $this->call(DeliveryCourrierSeeder::class);
        $this->call(DeliveryCourrierCitySeeder::class);
    }
}
