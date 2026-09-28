<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\User;
use App\Services\Operations\Performance\PerformanceTargetSeeder;
use App\Services\PostHogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user and their business — the
     * self-service twin of SuperAdmin\BusinessController@store.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'business_name' => ['required', 'string', 'max:255'],
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = DB::transaction(function () use ($input) {
            $business = Business::create([
                'name' => $input['business_name'],
                'slug' => Business::uniqueSlug($input['business_name']),
            ]);

            $user = User::create([
                'business_id' => $business->id,
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'role' => UserRole::ADMIN,
                'status' => UserStatus::ACTIVE,
            ]);

            // Business-wide performance targets, so an agent created
            // without explicit targets is still measured against something.
            app(PerformanceTargetSeeder::class)->seed($business);

            return $user;
        });

        // PostHog: Identify new user and track signup
        $posthog = app(PostHogService::class);
        $posthog->identify((string) $user->id, [
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role->value,
            'created_at' => $user->created_at?->toISOString(),
        ]);
        $posthog->capture((string) $user->id, 'user_signed_up', [
            'signup_method' => 'form',
        ]);

        return $user;
    }
}
