<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a password login stamps the user\'s last login time', function () {
    $user = User::factory()->create(['last_login_at' => null]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticated();
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('the business details page shows each user\'s last login', function () {
    $admin = makeBusinessUser(['last_login_at' => now()->subDay()]);

    $this->actingAs(superAdmin())->get(route('super-admin.businesses.show', $admin->business_id))
        ->assertInertia(fn ($page) => $page->where('users.0.last_login_at', fn ($value) => $value !== null)->etc());
});
