<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'email@example.com',
            'password' => 'password',
            'role' => UserRole::SUPER_ADMIN,
        ]);

        $this->call(EcommercePlatformSeeder::class);
        $this->call(DeliveryCourrierSeeder::class);
        $this->call(DeliveryCourrierCitySeeder::class);
        $this->call(PlanSeeder::class);
    }
}
