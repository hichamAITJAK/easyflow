<?php

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeBusiness(string $name, BusinessStatus $status = BusinessStatus::ACTIVE): Business
{
    return Business::create([
        'name' => $name,
        'slug' => Str::slug($name),
        'status' => $status,
    ]);
}

it('paginates businesses server-side instead of sending every row', function () {
    foreach (range(1, 25) as $index) {
        makeBusiness("Business {$index}");
    }

    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.index', ['per_page' => 10]))
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/businesses/index')
            ->has('businesses.data', 10)
            ->where('businesses.total', 25)
            ->where('businesses.last_page', 3)
        );
});

it('filters businesses by search across name and slug', function () {
    makeBusiness('Atlas Trading');
    makeBusiness('Sahara Goods');

    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.index', ['search' => 'atlas']))
        ->assertInertia(fn ($page) => $page
            ->has('businesses.data', 1)
            ->where('businesses.data.0.name', 'Atlas Trading')
        );

    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.index', ['search' => 'sahara-goods']))
        ->assertInertia(fn ($page) => $page
            ->has('businesses.data', 1)
            ->where('businesses.data.0.name', 'Sahara Goods')
        );
});

it('filters businesses by status', function () {
    makeBusiness('Active One', BusinessStatus::ACTIVE);
    makeBusiness('Suspended One', BusinessStatus::SUSPENDED);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.index', ['status' => 'suspended']))
        ->assertInertia(fn ($page) => $page
            ->has('businesses.data', 1)
            ->where('businesses.data.0.name', 'Suspended One')
        );
});

it('sorts by an allow-listed column and ignores anything else', function () {
    makeBusiness('Zulu');
    makeBusiness('Alpha');

    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.index', ['sort' => 'name', 'direction' => 'asc']))
        ->assertInertia(fn ($page) => $page->where('businesses.data.0.name', 'Alpha'));

    // An unlisted column must fall back to the default sort rather than
    // reaching the query builder as a raw column name.
    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.index', ['sort' => 'id); drop table businesses;--']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('businesses.data', 2));

    expect(Business::count())->toBe(2);
});

it('sorts by the withCount users column', function () {
    $busy = makeBusiness('Busy');
    $quiet = makeBusiness('Quiet');

    User::factory()->count(3)->create(['business_id' => $busy->id]);
    User::factory()->create(['business_id' => $quiet->id]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.index', ['sort' => 'users_count', 'direction' => 'desc']))
        ->assertInertia(fn ($page) => $page
            ->where('businesses.data.0.name', 'Busy')
            ->where('businesses.data.0.users_count', 3)
        );
});

it('echoes the active filters back to the page', function () {
    makeBusiness('Atlas Trading');

    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.index', [
            'search' => 'atlas',
            'status' => 'active',
            'per_page' => 50,
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.search', 'atlas')
            ->where('filters.status', 'active')
            ->where('filters.per_page', '50')
        );
});

it('caps per_page to the allow-list', function () {
    foreach (range(1, 30) as $index) {
        makeBusiness("Business {$index}");
    }

    $this->actingAs(superAdmin())
        ->get(route('super-admin.businesses.index', ['per_page' => 5000]))
        ->assertInertia(fn ($page) => $page->where('businesses.per_page', 20));
});

it('forbids non super admins from the businesses table', function () {
    $this->actingAs(makeBusinessUser(['role' => UserRole::ADMIN]))
        ->get(route('super-admin.businesses.index'))
        ->assertForbidden();
});
