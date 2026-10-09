<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'avatar' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ];
    }

    // ─── Role states ───────────────────────────────────────────────────────────

    /**
     * The platform owner. Deliberately business-less: a super admin manages
     * every business and belongs to none, so `business_id` is null rather
     * than the factory's default throwaway business.
     */
    public function superAdmin(): static
    {
        return $this->state(['role' => UserRole::SUPER_ADMIN, 'business_id' => null]);
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::ADMIN]);
    }

    public function confirmationAgent(): static
    {
        return $this->state(['role' => UserRole::CONFIRMATION_AGENT]);
    }

    public function fulfilmentAgent(): static
    {
        return $this->state(['role' => UserRole::FULFILMENT_AGENT]);
    }

    public function creativesEditor(): static
    {
        return $this->state(['role' => UserRole::CREATIVES_EDITOR]);
    }

    // ─── Status states ─────────────────────────────────────────────────────────

    public function invited(): static
    {
        return $this->state(['status' => UserStatus::INVITED]);
    }

    public function disabled(): static
    {
        return $this->state(['status' => UserStatus::DISABLED]);
    }

    // ─── Auth states ───────────────────────────────────────────────────────────

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
