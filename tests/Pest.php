<?php

use App\Models\Business;
use App\Models\EcommercePlatform;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create a user attached to a fresh business (defaults to role: admin, status: active).
 */
function makeBusinessUser(array $overrides = []): User
{
    $business = Business::create([
        'name' => 'Acme',
        'slug' => 'acme-'.uniqid(),
    ]);

    return User::factory()->create([
        'business_id' => $business->id,
        ...$overrides,
    ]);
}

/**
 * Create a platform-level super admin — no business of their own, which is
 * what distinguishes them from every tenant role.
 */
function superAdmin(array $overrides = []): User
{
    return User::factory()->superAdmin()->create($overrides);
}

/**
 * Create a store for the given business, attached to a fresh platform.
 */
function makeStore(int $businessId, array $overrides = []): Store
{
    $platform = EcommercePlatform::create([
        'name' => 'Shopify',
        'slug' => 'shopify-'.uniqid(),
    ]);

    return Store::create([
        'business_id' => $businessId,
        'platform_id' => $platform->id,
        'name' => 'Store '.uniqid(),
        'api_credentials' => 'encrypted-placeholder',
        'connection_status' => 'connected',
        ...$overrides,
    ]);
}

/**
 * Create an order for the given business with the minimum fields the
 * orders table requires.
 */
function makeOrder(int $businessId, array $overrides = []): Order
{
    return Order::create([
        'business_id' => $businessId,
        'customer_name' => 'Jane Doe',
        'customer_phone' => '+212600000000',
        'customer_phone_hash' => hash('sha256', '+212600000000'),
        'customer_address' => '123 Main St',
        'total_amount' => 100,
        'confirmation_status' => 'new',
        ...$overrides,
    ]);
}
