<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeCustomer(int $businessId, string $phone, array $overrides = []): Customer
{
    return Customer::create([
        'business_id' => $businessId,
        'name' => 'Customer '.$phone,
        'phone' => $phone,
        'phone_hash' => hash('sha256', $phone),
        'city' => 'Casablanca',
        'orders_count' => 0,
        'delivered_orders_count' => 0,
        'returned_orders_count' => 0,
        'is_best_customer' => false,
        'is_blacklisted' => false,
        ...$overrides,
    ]);
}

test('the blacklisted tab lists only blacklisted customers', function () {
    $admin = makeBusinessUser();

    makeCustomer($admin->business_id, '0611111111');
    makeCustomer($admin->business_id, '0622222222', ['is_best_customer' => true]);
    $blacklisted = makeCustomer($admin->business_id, '0633333333', ['is_blacklisted' => true]);

    $response = $this->actingAs($admin)->get(route('customers.index', ['blacklisted' => '1']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('customers/index')
        ->has('customers.data', 1)
        ->where('customers.data.0.id', $blacklisted->id)
        ->where('metrics.blacklisted', 1)
    );
});

test('the best tab excludes blacklisted customers and vice versa', function () {
    $admin = makeBusinessUser();

    $best = makeCustomer($admin->business_id, '0611111111', ['is_best_customer' => true]);
    makeCustomer($admin->business_id, '0622222222', ['is_blacklisted' => true]);

    $this->actingAs($admin)
        ->get(route('customers.index', ['best' => '1']))
        ->assertInertia(fn ($page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.id', $best->id)
        );
});

test('the default tab lists every customer regardless of flag', function () {
    $admin = makeBusinessUser();

    makeCustomer($admin->business_id, '0611111111');
    makeCustomer($admin->business_id, '0622222222', ['is_best_customer' => true]);
    makeCustomer($admin->business_id, '0633333333', ['is_blacklisted' => true]);

    $this->actingAs($admin)
        ->get(route('customers.index'))
        ->assertInertia(fn ($page) => $page->has('customers.data', 3));
});
