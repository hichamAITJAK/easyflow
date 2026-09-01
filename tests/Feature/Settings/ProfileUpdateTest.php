<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('a phone number can be added to the profile, normalized to 0XXXXXXXXX', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'phone' => '+212600000000',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->phone)->toBe('0600000000');
});

test('an uploaded avatar is stored and replaces any previous uploaded file', function () {
    Storage::fake('public');
    $user = User::factory()->create(['avatar' => 'avatars/old.jpg']);
    Storage::disk('public')->put('avatars/old.jpg', 'fake-contents');

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'avatar' => UploadedFile::fake()->image('new.jpg'),
    ])->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->getRawOriginal('avatar'))->toStartWith('avatars/');
    expect($user->getRawOriginal('avatar'))->not->toBe('avatars/old.jpg');
    Storage::disk('public')->assertMissing('avatars/old.jpg');
});

test('an avatar preset can be selected', function () {
    $user = User::factory()->create();

    $presets = collect(glob(public_path('assets/images/avatars/*.png')) ?: [])
        ->map(fn (string $path) => basename($path));

    expect($presets)->not->toBeEmpty();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'avatar_preset' => $presets->first(),
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->getRawOriginal('avatar'))
        ->toBe('assets/images/avatars/'.$presets->first());
});

test('an uploaded avatar can be removed', function () {
    Storage::fake('public');
    $user = User::factory()->create(['avatar' => 'avatars/old.jpg']);
    Storage::disk('public')->put('avatars/old.jpg', 'fake-contents');

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'remove_avatar' => '1',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->avatar)->toBeNull();
    Storage::disk('public')->assertMissing('avatars/old.jpg');
});

test('role and account status cannot be changed from the profile page', function () {
    $user = User::factory()->create([
        'role' => UserRole::CONFIRMATION_AGENT,
        'status' => UserStatus::ACTIVE,
    ]);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'role' => UserRole::ADMIN->value,
        'status' => UserStatus::DISABLED->value,
    ])->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->role)->toBe(UserRole::CONFIRMATION_AGENT);
    expect($user->status)->toBe(UserStatus::ACTIVE);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
