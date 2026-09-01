<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function actingAsMobileUser(array $overrides = []): array
{
    $user = makeBusinessUser(['status' => UserStatus::ACTIVE, ...$overrides]);
    $token = $user->createToken('device')->plainTextToken;

    return [$user, $token];
}

test('profile information can be updated and the new user object is returned', function () {
    [$user, $token] = actingAsMobileUser();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.profile.update'), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

    $response->assertOk();
    $response->assertJsonStructure(['user' => ['id', 'name', 'email', 'phone', 'avatar', 'role', 'business_id']]);
    $response->assertJson(['user' => ['name' => 'Updated Name', 'email' => 'updated@example.com']]);

    $user->refresh();
    expect($user->name)->toBe('Updated Name');
    expect($user->email)->toBe('updated@example.com');
});

test('a phone number is normalized to 0XXXXXXXXX', function () {
    [$user, $token] = actingAsMobileUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '+212600000000',
        ])
        ->assertOk();

    expect($user->refresh()->phone)->toBe('0600000000');
});

test('updating the profile rejects an email already used by another user', function () {
    [$user, $token] = actingAsMobileUser();
    $other = User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.profile.update'), [
            'name' => $user->name,
            'email' => $other->email,
        ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('email');
});

test('updating the profile rejects a phone number already used by another user', function () {
    [$user, $token] = actingAsMobileUser();
    User::factory()->create(['phone' => '0611111111']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '0611111111',
        ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('phone');
});

test('role cannot be changed from the mobile profile endpoint', function () {
    [$user, $token] = actingAsMobileUser(['role' => UserRole::CONFIRMATION_AGENT]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => UserRole::ADMIN->value,
        ])
        ->assertOk();

    expect($user->refresh()->role)->toBe(UserRole::CONFIRMATION_AGENT);
});

test('updating the profile requires authentication', function () {
    $this->patchJson(route('api.mobile.profile.update'), [
        'name' => 'Someone',
        'email' => 'someone@example.com',
    ])->assertUnauthorized();
});

test('name and email are required', function () {
    [, $token] = actingAsMobileUser();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.mobile.profile.update'), []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name', 'email']);
});
