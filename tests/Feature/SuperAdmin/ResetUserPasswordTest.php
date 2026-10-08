<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('lets a super admin reset a business user password and signs out their mobile sessions', function () {
    $admin = makeBusinessUser();
    $admin->createToken('mobile');

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.users.password', [$admin->business_id, $admin]), [
            'password' => 'NewSecret123!',
            'password_confirmation' => 'NewSecret123!',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Hash::check('NewSecret123!', $admin->fresh()->password))->toBeTrue()
        ->and($admin->tokens()->count())->toBe(0);
});

it('refuses a user from another business, a weak password, and a non super admin', function () {
    $admin = makeBusinessUser();
    $other = makeBusinessUser();

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.users.password', [$admin->business_id, $other]), [
            'password' => 'NewSecret123!', 'password_confirmation' => 'NewSecret123!',
        ])->assertNotFound();

    $this->actingAs(superAdmin())
        ->from(route('super-admin.businesses.show', $admin->business_id))
        ->patch(route('super-admin.businesses.users.password', [$admin->business_id, $admin]), [
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

    $this->actingAs($admin)
        ->patch(route('super-admin.businesses.users.password', [$admin->business_id, $admin]), [
            'password' => 'NewSecret123!', 'password_confirmation' => 'NewSecret123!',
        ])->assertForbidden();

    expect(Hash::check('NewSecret123!', User::find($admin->id)->password))->toBeFalse();
});
