<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The factory starts every business on a trial, which is itself a history
 * row — so tests that count rows create businesses without one.
 */
function businessWithoutTrial(array $overrides = []): Business
{
    $business = Business::factory()->unsubscribed()->create($overrides);

    return $business->fresh();
}

it('paginates the history table server-side', function () {
    foreach (range(1, 25) as $index) {
        Subscription::factory()->active()->create([
            'business_id' => businessWithoutTrial(['name' => "Business {$index}"])->id,
        ]);
    }

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', ['history_per_page' => 10]))
        ->assertInertia(fn ($page) => $page
            ->component('super-admin/subscriptions/index')
            ->has('history.data', 10)
            ->where('history.total', 25)
            ->where('history.last_page', 3)
        );
});

it('keeps pending requests out of the history table', function () {
    $pendingBusiness = businessWithoutTrial(['name' => 'Waiting Co']);
    Subscription::factory()->pending()->create(['business_id' => $pendingBusiness->id]);

    $activeBusiness = businessWithoutTrial(['name' => 'Paid Co']);
    Subscription::factory()->active()->create(['business_id' => $activeBusiness->id]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index'))
        ->assertInertia(fn ($page) => $page
            ->has('pending', 1)
            ->has('history.data', 1)
            ->where('history.data.0.businessName', 'Paid Co')
        );
});

it('searches history by business name and by reference code', function () {
    Subscription::factory()->active()->create([
        'business_id' => businessWithoutTrial(['name' => 'Atlas Trading'])->id,
        'reference_code' => 'SUB-ATLAS',
    ]);
    Subscription::factory()->active()->create([
        'business_id' => businessWithoutTrial(['name' => 'Sahara Goods'])->id,
        'reference_code' => 'SUB-SAHARA',
    ]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', ['history_search' => 'atlas trad']))
        ->assertInertia(fn ($page) => $page
            ->has('history.data', 1)
            ->where('history.data.0.businessName', 'Atlas Trading')
        );

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', ['history_search' => 'SUB-SAHARA']))
        ->assertInertia(fn ($page) => $page
            ->has('history.data', 1)
            ->where('history.data.0.businessName', 'Sahara Goods')
        );
});

it('filters history by status', function () {
    Subscription::factory()->active()->create([
        'business_id' => businessWithoutTrial(['name' => 'Active Co'])->id,
    ]);
    Subscription::factory()->create([
        'business_id' => businessWithoutTrial(['name' => 'Trial Co'])->id,
        'status' => SubscriptionStatus::TRIALING,
    ]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', ['history_status' => 'trialing']))
        ->assertInertia(fn ($page) => $page
            ->has('history.data', 1)
            ->where('history.data.0.businessName', 'Trial Co')
        );
});

it('sorts history by the related business name', function () {
    Subscription::factory()->active()->create([
        'business_id' => businessWithoutTrial(['name' => 'Zulu Co'])->id,
    ]);
    Subscription::factory()->active()->create([
        'business_id' => businessWithoutTrial(['name' => 'Alpha Co'])->id,
    ]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', [
            'history_sort' => 'business_name',
            'history_direction' => 'asc',
        ]))
        ->assertInertia(fn ($page) => $page->where('history.data.0.businessName', 'Alpha Co'));

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', [
            'history_sort' => 'business_name',
            'history_direction' => 'desc',
        ]))
        ->assertInertia(fn ($page) => $page->where('history.data.0.businessName', 'Zulu Co'));
});

it('sorts history by the related plan name', function () {
    $cheap = Plan::factory()->create(['name' => 'Aardvark']);
    $pricey = Plan::factory()->create(['name' => 'Zebra']);

    Subscription::factory()->active()->create([
        'business_id' => businessWithoutTrial()->id,
        'plan_id' => $pricey->id,
    ]);
    Subscription::factory()->active()->create([
        'business_id' => businessWithoutTrial()->id,
        'plan_id' => $cheap->id,
    ]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', [
            'history_sort' => 'plan_name',
            'history_direction' => 'asc',
        ]))
        ->assertInertia(fn ($page) => $page->where('history.data.0.planName', 'Aardvark'));
});

it('ignores a sort column that is not allow-listed', function () {
    Subscription::factory()->active()->create([
        'business_id' => businessWithoutTrial()->id,
    ]);

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', [
            'history_sort' => 'notes); drop table subscriptions;--',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('history.data', 1));

    expect(Subscription::count())->toBe(1);
});

it('caps history per_page to the allow-list', function () {
    foreach (range(1, 30) as $index) {
        Subscription::factory()->active()->create([
            'business_id' => businessWithoutTrial(['name' => "Business {$index}"])->id,
        ]);
    }

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', ['history_per_page' => 5000]))
        ->assertInertia(fn ($page) => $page->where('history.per_page', 20));
});

it('echoes the history filters back to the page', function () {
    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', [
            'history_search' => 'atlas',
            'history_status' => 'active',
            'history_per_page' => 50,
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('historyFilters.history_search', 'atlas')
            ->where('historyFilters.history_status', 'active')
            ->where('historyFilters.history_per_page', '50')
        );
});

it('paginates history under its own page key so it never collides', function () {
    foreach (range(1, 25) as $index) {
        Subscription::factory()->active()->create([
            'business_id' => businessWithoutTrial(['name' => "Business {$index}"])->id,
        ]);
    }

    $this->actingAs(superAdmin())
        ->get(route('super-admin.subscriptions.index', [
            'history_per_page' => 10,
            'history_page' => 3,
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('history.current_page', 3)
            ->has('history.data', 5)
        );
});

it('forbids non super admins from the subscriptions page', function () {
    $this->actingAs(makeBusinessUser(['role' => UserRole::ADMIN]))
        ->get(route('super-admin.subscriptions.index'))
        ->assertForbidden();
});
