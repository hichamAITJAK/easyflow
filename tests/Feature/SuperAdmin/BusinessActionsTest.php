<?php

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows a business detail page with its team and stores', function () {
    $business = Business::factory()->create(['name' => 'Atlas Trading']);
    User::factory()->count(2)->create(['business_id' => $business->id]);
    $courier = DeliveryCourrier::create(['name' => 'Sendit', 'slug' => 'sendit']);
    DeliveryAccount::create([
        'business_id' => $business->id, 'courier_id' => $courier->id, 'label' => 'main',
        'api_credentials' => '{}', 'status' => 'active',
    ]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.show', $business))
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/businesses/show')
            ->where('business.name', 'Atlas Trading')
            ->where('business.users_count', 2)
            ->has('users', 2)
            ->has('stores')
            ->has('deliveryAccounts', 1)
            ->where('deliveryAccounts.0.courier.name', 'Sendit')
            ->missing('deliveryAccounts.0.api_credentials')
        );
});

it('updates a business name and slug', function () {
    $business = Business::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.update', $business), [
            'name' => 'New Name',
            'slug' => 'new-name',
        ])
        ->assertRedirect(route('super-admin.businesses.show', $business));

    expect($business->fresh())
        ->name->toBe('New Name')
        ->slug->toBe('new-name');
});

it('rejects a slug that is already taken by another business', function () {
    Business::factory()->create(['slug' => 'taken']);
    $business = Business::factory()->create(['slug' => 'mine']);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.update', $business), [
            'name' => 'Whatever',
            'slug' => 'taken',
        ])
        ->assertSessionHasErrors('slug');

    expect($business->fresh()->slug)->toBe('mine');
});

it('lets a business keep its own slug when only the name changes', function () {
    $business = Business::factory()->create(['name' => 'Old', 'slug' => 'keep-me']);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.update', $business), [
            'name' => 'New',
            'slug' => 'keep-me',
        ])
        ->assertSessionHasNoErrors();

    expect($business->fresh()->name)->toBe('New');
});

it('rejects a malformed slug', function () {
    $business = Business::factory()->create(['slug' => 'fine']);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.update', $business), [
            'name' => 'Name',
            'slug' => 'Not A Slug',
        ])
        ->assertSessionHasErrors('slug');
});

it('suspends an active business, locking its users out of login', function () {
    $business = Business::factory()->create(['status' => BusinessStatus::ACTIVE]);
    $member = User::factory()->create(['business_id' => $business->id]);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.status', $business), ['status' => 'suspended'])
        ->assertRedirect();

    expect($business->fresh()->status)->toBe(BusinessStatus::SUSPENDED);

    // The status change is only meaningful if it actually blocks the tenant,
    // so drop the super admin's session before trying the member's login.
    auth()->logout();

    $this->post(route('login.store'), [
        'email' => $member->email,
        'password' => 'password',
    ])->assertRedirect(route('account-status', ['reason' => 'business-suspended']));
});

it('reactivates a suspended business', function () {
    $business = Business::factory()->suspended()->create();

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.status', $business), ['status' => 'active'])
        ->assertRedirect();

    expect($business->fresh()->status)->toBe(BusinessStatus::ACTIVE);
});

it('cancels a business', function () {
    $business = Business::factory()->create(['status' => BusinessStatus::ACTIVE]);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.status', $business), ['status' => 'cancelled'])
        ->assertRedirect();

    expect($business->fresh()->status)->toBe(BusinessStatus::CANCELLED);
});

it('refuses to move a cancelled business back out of its terminal state', function () {
    $business = Business::factory()->create(['status' => BusinessStatus::CANCELLED]);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.status', $business), ['status' => 'active'])
        ->assertSessionHasErrors('status');

    expect($business->fresh()->status)->toBe(BusinessStatus::CANCELLED);
});

it('rejects an unknown status value', function () {
    $business = Business::factory()->create(['status' => BusinessStatus::ACTIVE]);

    $this->actingAs(superAdmin())
        ->patch(route('super-admin.businesses.status', $business), ['status' => 'deleted'])
        ->assertSessionHasErrors('status');

    expect($business->fresh()->status)->toBe(BusinessStatus::ACTIVE);
});

it('forbids non super admins from every business action', function () {
    $business = Business::factory()->create();
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)
        ->get(route('super-admin.businesses.show', $business))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('super-admin.businesses.edit', $business))
        ->assertForbidden();

    $this->actingAs($admin)
        ->patch(route('super-admin.businesses.update', $business), [
            'name' => 'Hijacked',
            'slug' => 'hijacked',
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patch(route('super-admin.businesses.status', $business), ['status' => 'cancelled'])
        ->assertForbidden();

    expect($business->fresh()->status)->toBe(BusinessStatus::ACTIVE);
});
