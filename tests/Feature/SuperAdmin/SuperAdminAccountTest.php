<?php

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The platform owner stands outside every tenant. Their account must not
 * depend on any business existing — they are the one who creates and
 * removes businesses in the first place.
 */
it('gives a seeded super admin no business', function () {
    $user = User::factory()->superAdmin()->create();

    expect($user->business_id)->toBeNull();
});

it('strips a business from a super admin even when one is supplied', function () {
    $business = Business::factory()->create();

    $user = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'business_id' => $business->id,
    ]);

    expect($user->fresh()->business_id)->toBeNull();
});

it('keeps the super admin account when every business is deleted', function () {
    $superAdmin = superAdmin();
    Business::factory()->count(3)->create();

    Business::query()->delete();

    expect(Business::count())->toBe(0)
        ->and(User::find($superAdmin->id))->not->toBeNull();
});

it('lets a super admin log in and reach the platform panel with no businesses at all', function () {
    $superAdmin = superAdmin();
    Business::query()->delete();

    $this->post(route('login.store'), [
        'email' => $superAdmin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($superAdmin);
    $this->get(route('super-admin.home'))->assertRedirect(route('super-admin.businesses.index'));
    $this->get(route('super-admin.businesses.index'))->assertOk();
});
