<?php

use App\Models\Business;
use App\Models\User;
use App\Rules\UniqueUserPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

function businessPayload(array $overrides = []): array
{
    return [
        'business_name' => 'Atlas Trading',
        'business_status' => 'active',
        'admin_name' => 'Hicham',
        'admin_email' => 'hicham@example.com',
        'admin_phone' => '0711041134',
        'admin_password' => 'a-Strong-passw0rd!',
        'admin_password_confirmation' => 'a-Strong-passw0rd!',
        ...$overrides,
    ];
}

/**
 * The regression: this used to reach the insert and die on the
 * users_phone_unique index as a 500.
 */
it('reports a phone already in use as a field error, not a server error', function () {
    makeBusinessUser(['phone' => '0711041134']);

    $this->actingAs(superAdmin())
        ->post(route('super-admin.businesses.store'), businessPayload())
        ->assertSessionHasErrors('admin_phone');

    expect(Business::where('name', 'Atlas Trading')->exists())->toBeFalse()
        ->and(User::where('email', 'hicham@example.com')->exists())->toBeFalse();
});

it('catches the same number typed in a different format', function (string $typed) {
    makeBusinessUser(['phone' => '0711041134']);

    $this->actingAs(superAdmin())
        ->post(route('super-admin.businesses.store'), businessPayload(['admin_phone' => $typed]))
        ->assertSessionHasErrors('admin_phone');
})->with(['+212 711 04 11 34', '0711 041 134', '212711041134', '07-11-04-11-34']);

it('still creates the business when the phone is free', function () {
    makeBusinessUser(['phone' => '0622222222']);

    $this->actingAs(superAdmin())
        ->post(route('super-admin.businesses.store'), businessPayload())
        ->assertSessionHasNoErrors();

    expect(User::where('email', 'hicham@example.com')->sole()->phone)->toBe('0711041134');
});

it('lets a user keep their own number when it is ignored', function () {
    $user = makeBusinessUser(['phone' => '0711041134']);

    $own = Validator::make(['phone' => '+212 711 04 11 34'], ['phone' => [new UniqueUserPhone($user->id)]]);
    $other = Validator::make(['phone' => '+212 711 04 11 34'], ['phone' => [new UniqueUserPhone]]);

    expect($own->passes())->toBeTrue()
        ->and($other->passes())->toBeFalse();
});
