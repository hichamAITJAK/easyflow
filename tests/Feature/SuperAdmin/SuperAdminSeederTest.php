<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a business-less super admin from the configured credentials', function () {
    config(['platform.super_admin' => [
        'name' => 'Owner',
        'email' => 'owner@easyflow.ma',
        'password' => 'a-real-password',
    ]]);

    $this->seed(SuperAdminSeeder::class);

    $user = User::where('email', 'owner@easyflow.ma')->sole();

    expect($user->role)->toBe(UserRole::SUPER_ADMIN)
        ->and($user->business_id)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();

    $this->post(route('login.store'), [
        'email' => 'owner@easyflow.ma',
        'password' => 'a-real-password',
    ]);

    $this->assertAuthenticatedAs($user);
});

it('leaves an existing super admin and its password untouched when re-run', function () {
    config(['platform.super_admin' => [
        'name' => 'Owner',
        'email' => 'owner@easyflow.ma',
        'password' => 'first-password',
    ]]);

    $this->seed(SuperAdminSeeder::class);

    config(['platform.super_admin.password' => 'second-password']);

    $this->seed(SuperAdminSeeder::class);

    expect(User::where('email', 'owner@easyflow.ma')->count())->toBe(1);

    $this->post(route('login.store'), [
        'email' => 'owner@easyflow.ma',
        'password' => 'first-password',
    ]);

    $this->assertAuthenticated();
});

/**
 * A guessable default on a public server is worse than no account, so
 * production without credentials creates nothing.
 */
it('creates nothing in production when no credentials are configured', function () {
    config(['platform.super_admin' => ['name' => null, 'email' => null, 'password' => null]]);
    app()->detectEnvironment(fn () => 'production');

    // Run directly rather than through `db:seed`: in production that
    // command stops to ask for confirmation, which is artisan's behaviour
    // and not what this test is about.
    app(SuperAdminSeeder::class)->run();

    expect(User::where('role', UserRole::SUPER_ADMIN)->count())->toBe(0);
});
