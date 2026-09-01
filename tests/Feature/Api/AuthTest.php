<?php

use App\Enums\BusinessStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user can log in with valid credentials and receives a token', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
        'status' => UserStatus::ACTIVE,
    ]);

    $response = $this->postJson(route('api.login'), [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'business_id']]);
    expect($user->tokens()->count())->toBe(1);
});

test('login is rejected with the wrong password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
        'status' => UserStatus::ACTIVE,
    ]);

    $response = $this->postJson(route('api.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('email');
    expect($user->tokens()->count())->toBe(0);
});

test('login is rejected for a disabled user', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
        'status' => UserStatus::DISABLED,
    ]);

    $response = $this->postJson(route('api.login'), [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertUnprocessable();
    expect($user->tokens()->count())->toBe(0);
});

test('login is rejected for a user whose business is suspended', function () {
    $user = makeBusinessUser([
        'password' => bcrypt('password123'),
        'status' => UserStatus::ACTIVE,
    ]);
    $user->business->update(['status' => BusinessStatus::SUSPENDED]);

    $response = $this->postJson(route('api.login'), [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertUnprocessable();
    expect($user->tokens()->count())->toBe(0);
});

test('an authenticated user can log out, revoking only the current token', function () {
    $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
    $token = $user->createToken('iPhone 15');
    $user->createToken('iPad');

    $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->postJson(route('api.logout'));

    $response->assertOk();
    expect($user->tokens()->count())->toBe(1);
});

test('fulfillment routes reject a request with no token', function () {
    $this->getJson(route('api.mobile.fulfillment.summary'))->assertUnauthorized();
});
