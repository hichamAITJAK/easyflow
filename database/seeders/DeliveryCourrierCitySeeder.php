<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DeliveryCourrierCitySeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->call('couriers:sync-cities');
    }
}
