<?php

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

/**
 * Suspending or cancelling a business has to stop work *now*, not at each
 * user's next login. These cover the mid-session case: a user who is
 * already signed in when the switch is flipped.
 */
it('signs out an already-authenticated user the moment their business is suspended', function (string $status, string $reason) {
    $business = Business::factory()->create(['status' => BusinessStatus::ACTIVE]);
    $member = User::factory()->create(['business_id' => $business->id]);

    // The member is signed in and working before anything changes.
    $this->actingAs($member)->get(route('dashboard'))->assertOk();

    $business->update(['status' => BusinessStatus::from($status)]);

    // actingAs reuses the in-memory model, whose `business` relation was
    // loaded while the business was still active; a real request resolves
    // the user (and the relation) fresh from the database each time.
    $this->actingAs($member->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('account-status', ['reason' => $reason]));

    $this->assertGuest();
})->with([
    ['suspended', 'business-suspended'],
    ['cancelled', 'business-cancelled'],
]);

it('signs out a user whose own account is disabled mid-session', function () {
    $member = makeBusinessUser(['status' => UserStatus::ACTIVE]);

    $this->actingAs($member)->get(route('dashboard'))->assertOk();

    $member->update(['status' => UserStatus::DISABLED]);

    $this->actingAs($member->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('account-status', ['reason' => 'user-disabled']));

    $this->assertGuest();
});

it('deletes every mobile token of a business when it is suspended', function () {
    $business = Business::factory()->create(['status' => BusinessStatus::ACTIVE]);

    $agent = User::factory()->create([
        'business_id' => $business->id,
        'role' => UserRole::FULFILMENT_AGENT,
    ]);
    $agent->createToken('phone');

    // A user at a different business must keep their token.
    $bystander = makeBusinessUser();
    $bystander->createToken('phone');

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.status', $business), ['status' => 'suspended'])
        ->assertRedirect();

    expect(PersonalAccessToken::where('tokenable_id', $agent->id)->count())->toBe(0)
        ->and(PersonalAccessToken::where('tokenable_id', $bystander->id)->count())->toBe(1);
});

it('keeps mobile tokens intact when a business is reactivated', function () {
    $business = Business::factory()->suspended()->create();
    $agent = User::factory()->create([
        'business_id' => $business->id,
        'role' => UserRole::FULFILMENT_AGENT,
    ]);
    $agent->createToken('phone');

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.status', $business), ['status' => 'active'])
        ->assertRedirect();

    expect(PersonalAccessToken::where('tokenable_id', $agent->id)->count())->toBe(1);
});

it('rejects a mobile API request once the business is suspended', function () {
    $business = Business::factory()->create(['status' => BusinessStatus::ACTIVE]);
    $agent = User::factory()->create([
        'business_id' => $business->id,
        'role' => UserRole::FULFILMENT_AGENT,
    ]);

    $token = $agent->createToken('phone')->plainTextToken;

    // The token works while the business is active...
    $this->withToken($token)
        ->getJson(route('api.mobile.dashboard'))
        ->assertOk();

    // ...and stops the moment it isn't, even holding the same token.
    $business->update(['status' => BusinessStatus::SUSPENDED]);

    // Sanctum caches the resolved token owner on the guard for the lifetime
    // of the test process; a real deployment resolves it per request, so the
    // guard is cleared here to model that boundary honestly.
    app('auth')->forgetGuards();

    $this->withToken($token)
        ->getJson(route('api.mobile.dashboard'))
        ->assertForbidden()
        ->assertJson(['reason' => 'business-suspended']);
});

it('still lets a fulfilment agent use the mobile API of an active business', function () {
    $business = Business::factory()->create(['status' => BusinessStatus::ACTIVE]);
    $agent = User::factory()->create([
        'business_id' => $business->id,
        'role' => UserRole::FULFILMENT_AGENT,
    ]);

    // The web-only ban must not leak into the mobile surface.
    $this->withToken($agent->createToken('phone')->plainTextToken)
        ->getJson(route('api.mobile.dashboard'))
        ->assertOk();
});

it('leaves a healthy business alone', function () {
    $member = makeBusinessUser();

    $this->actingAs($member)->get(route('dashboard'))->assertOk();
    $this->actingAs($member)->get(route('dashboard'))->assertOk();

    $this->assertAuthenticated();
});
