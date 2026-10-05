<?php

use App\Enums\StoreConnectionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AgentScope;
use App\Models\CommissionRule;
use App\Models\EcommercePlatform;
use App\Models\PerformanceTarget;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAdminWithStoreAndProduct(): array
{
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $business = $admin->business;

    $platform = EcommercePlatform::create(['name' => 'Shopify', 'slug' => 'Shopify']);
    $store = Store::create([
        'business_id' => $business->id,
        'platform_id' => $platform->id,
        'name' => 'Test Store',
        'api_credentials' => '',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);
    $product = Product::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'external_product_id' => 'p1',
        'name' => 'Widget',
        'price' => 10,
    ]);

    return [$admin, $store, $product];
}

test('admin creates a confirmation agent with commission mode, scope, and a performance target', function () {
    [$admin, $store, $product] = makeAdminWithStoreAndProduct();

    $response = $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Agent Commission',
        'email' => 'agent-commission@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'commission',
        'trigger_status' => 'delivered',
        'amount_type' => 'fixed',
        'amount' => 15,
        'store_ids' => [$store->id],
        'product_ids' => [$product->id],
        'targets' => [
            ['metric' => 'confirmation_rate', 'target_percentage' => 80, 'period' => 'weekly'],
        ],
    ]);

    $response->assertRedirect(route('users.index'));
    $response->assertSessionHasNoErrors();

    $agent = User::where('email', 'agent-commission@example.com')->firstOrFail();
    expect($agent->role)->toBe(UserRole::CONFIRMATION_AGENT);

    $rule = CommissionRule::where('user_id', $agent->id)->firstOrFail();
    expect($rule->payment_mode->value)->toBe('commission');
    expect((float) $rule->amount)->toBe(15.0);
    expect($rule->trigger_status)->toBe('delivered');

    expect(AgentScope::where('user_id', $agent->id)->count())->toBe(2);
    expect(AgentScope::where('user_id', $agent->id)->where('store_id', $store->id)->exists())->toBeTrue();
    expect(AgentScope::where('user_id', $agent->id)->where('product_id', $product->id)->exists())->toBeTrue();

    $target = PerformanceTarget::where('user_id', $agent->id)->firstOrFail();
    expect($target->metric->value)->toBe('confirmation_rate');
    expect((float) $target->target_percentage)->toBe(80.0);
});

test('admin creates a confirmation agent with a default rate plus store and product overrides', function () {
    [$admin, $store, $product] = makeAdminWithStoreAndProduct();

    $secondStore = Store::create([
        'business_id' => $admin->business_id,
        'platform_id' => $store->platform_id,
        'name' => 'Second Store',
        'api_credentials' => '',
        'connection_status' => StoreConnectionStatus::CONNECTED,
    ]);

    $response = $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Agent Overrides',
        'email' => 'agent-overrides@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'commission',
        'trigger_status' => 'delivered',
        'amount_type' => 'fixed',
        'amount' => 15,
        'overrides' => [
            ['store_id' => $secondStore->id, 'amount_type' => 'fixed', 'amount' => 20],
            ['product_id' => $product->id, 'amount_type' => 'percentage', 'amount' => 8],
        ],
    ]);

    $response->assertRedirect(route('users.index'));
    $response->assertSessionHasNoErrors();

    $agent = User::where('email', 'agent-overrides@example.com')->firstOrFail();
    $rules = CommissionRule::where('user_id', $agent->id)->get();

    expect($rules)->toHaveCount(3);

    $default = $rules->firstWhere(fn ($rule) => $rule->store_id === null && $rule->product_id === null);
    expect((float) $default->amount)->toBe(15.0);

    $storeOverride = $rules->firstWhere('store_id', $secondStore->id);
    expect($storeOverride)->not->toBeNull();
    expect($storeOverride->amount_type->value)->toBe('fixed');
    expect((float) $storeOverride->amount)->toBe(20.0);

    $productOverride = $rules->firstWhere('product_id', $product->id);
    expect($productOverride)->not->toBeNull();
    expect($productOverride->amount_type->value)->toBe('percentage');
    expect((float) $productOverride->amount)->toBe(8.0);
});

test('an override row with both store_id and product_id is rejected', function () {
    [$admin, $store, $product] = makeAdminWithStoreAndProduct();

    $response = $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Bad Override Agent',
        'email' => 'bad-override@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'commission',
        'trigger_status' => 'delivered',
        'amount_type' => 'fixed',
        'amount' => 15,
        'overrides' => [
            ['store_id' => $store->id, 'product_id' => $product->id, 'amount_type' => 'fixed', 'amount' => 20],
        ],
    ]);

    $response->assertSessionHasErrors(['overrides.0.store_id']);
    expect(User::where('email', 'bad-override@example.com')->exists())->toBeFalse();
});

test('updating an agent to salary mode clears their existing overrides', function () {
    [$admin, $store] = makeAdminWithStoreAndProduct();

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Switching Agent',
        'email' => 'switching-agent@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'commission',
        'trigger_status' => 'delivered',
        'amount_type' => 'fixed',
        'amount' => 15,
        'overrides' => [
            ['store_id' => $store->id, 'amount_type' => 'fixed', 'amount' => 20],
        ],
    ]);

    $agent = User::where('email', 'switching-agent@example.com')->firstOrFail();
    expect(CommissionRule::where('user_id', $agent->id)->count())->toBe(2);

    $response = $this->actingAs($admin)->put(route('users.update', $agent), [
        'name' => 'Switching Agent',
        'email' => 'switching-agent@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'payment_mode' => 'salary',
        'salary_amount' => 3000,
        'salary_period' => 'monthly',
    ]);

    $response->assertRedirect(route('users.index'));
    $response->assertSessionHasNoErrors();

    $rules = CommissionRule::where('user_id', $agent->id)->get();
    expect($rules)->toHaveCount(1);
    expect($rules->first()->payment_mode->value)->toBe('salary');
});

test('admin creates a confirmation agent with salary mode and no scope', function () {
    [$admin] = makeAdminWithStoreAndProduct();

    $response = $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Agent Salary',
        'email' => 'agent-salary@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'salary',
        'salary_amount' => 3000,
        'salary_period' => 'monthly',
    ]);

    $response->assertRedirect(route('users.index'));
    $response->assertSessionHasNoErrors();

    $agent = User::where('email', 'agent-salary@example.com')->firstOrFail();
    $rule = CommissionRule::where('user_id', $agent->id)->firstOrFail();

    expect($rule->payment_mode->value)->toBe('salary');
    expect((float) $rule->salary_amount)->toBe(3000.0);
    expect($rule->salary_period->value)->toBe('monthly');
    expect(AgentScope::where('user_id', $agent->id)->count())->toBe(0);
});

test('admin creates a fulfilment agent with per-parcel commission', function () {
    [$admin] = makeAdminWithStoreAndProduct();

    $response = $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Fulfilment Agent',
        'email' => 'fulfilment@example.com',
        'role' => 'fulfilment_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'commission',
        'amount' => 5,
    ]);

    $response->assertRedirect(route('users.index'));
    $response->assertSessionHasNoErrors();

    $agent = User::where('email', 'fulfilment@example.com')->firstOrFail();
    $rule = CommissionRule::where('user_id', $agent->id)->firstOrFail();

    expect($rule->payment_mode->value)->toBe('commission');
    expect($rule->trigger_status)->toBe('ready_for_pickup');
    expect($rule->amount_type->value)->toBe('fixed');
    expect((float) $rule->amount)->toBe(5.0);
});

test('admin creates a confirmation agent paid a salary plus commission', function () {
    [$admin, $store] = makeAdminWithStoreAndProduct();

    $response = $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Agent Both',
        'email' => 'agent-both@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'salary_and_commission',
        'salary_amount' => 2500,
        'salary_period' => 'monthly',
        'trigger_status' => 'confirmed',
        'amount_type' => 'fixed',
        'amount' => 8,
        'overrides' => [
            ['store_id' => $store->id, 'amount_type' => 'fixed', 'amount' => 12],
        ],
    ]);

    $response->assertRedirect(route('users.index'));
    $response->assertSessionHasNoErrors();

    $agent = User::where('email', 'agent-both@example.com')->firstOrFail();
    $rule = CommissionRule::where('user_id', $agent->id)
        ->whereNull('store_id')
        ->whereNull('product_id')
        ->firstOrFail();

    // Both halves are stored on the one default rule.
    expect($rule->payment_mode->value)->toBe('salary_and_commission');
    expect((float) $rule->salary_amount)->toBe(2500.0);
    expect($rule->salary_period->value)->toBe('monthly');
    expect($rule->trigger_status)->toBe('confirmed');
    expect($rule->amount_type->value)->toBe('fixed');
    expect((float) $rule->amount)->toBe(8.0);

    // Overrides ride on the commission half, so they are kept.
    expect(CommissionRule::where('user_id', $agent->id)->where('store_id', $store->id)->count())->toBe(1);
});

test('admin creates a fulfilment agent paid a salary plus per-parcel pay', function () {
    [$admin] = makeAdminWithStoreAndProduct();

    $response = $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Fulfilment Both',
        'email' => 'fulfilment-both@example.com',
        'role' => 'fulfilment_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'salary_and_commission',
        'salary_amount' => 2000,
        'salary_period' => 'weekly',
        'amount' => 3,
    ]);

    $response->assertRedirect(route('users.index'));
    $response->assertSessionHasNoErrors();

    $agent = User::where('email', 'fulfilment-both@example.com')->firstOrFail();
    $rule = CommissionRule::where('user_id', $agent->id)->firstOrFail();

    expect($rule->payment_mode->value)->toBe('salary_and_commission');
    expect((float) $rule->salary_amount)->toBe(2000.0);
    expect($rule->salary_period->value)->toBe('weekly');
    expect($rule->trigger_status)->toBe('ready_for_pickup');
    expect((float) $rule->amount)->toBe(3.0);
});

test('salary plus commission requires both the salary and the commission fields', function () {
    [$admin] = makeAdminWithStoreAndProduct();

    $response = $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Half Agent',
        'email' => 'half-agent@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'salary_and_commission',
    ]);

    $response->assertSessionHasErrors(['salary_amount', 'salary_period', 'trigger_status', 'amount_type', 'amount']);
    expect(User::where('email', 'half-agent@example.com')->exists())->toBeFalse();
});

test('switching an agent from both to commission only clears the salary', function () {
    [$admin] = makeAdminWithStoreAndProduct();
    $base = [
        'name' => 'Switch Agent',
        'email' => 'switch-agent@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'trigger_status' => 'confirmed',
        'amount_type' => 'fixed',
        'amount' => 8,
    ];

    $this->actingAs($admin)->post(route('users.store'), [
        ...$base,
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'salary_and_commission',
        'salary_amount' => 2500,
        'salary_period' => 'monthly',
    ])->assertSessionHasNoErrors();

    $agent = User::where('email', 'switch-agent@example.com')->firstOrFail();

    // A stale salary is submitted on purpose: the mode decides what is kept.
    $this->actingAs($admin)->put(route('users.update', $agent), [
        ...$base,
        'payment_mode' => 'commission',
        'salary_amount' => 2500,
        'salary_period' => 'monthly',
    ])->assertSessionHasNoErrors();

    $rule = CommissionRule::where('user_id', $agent->id)->firstOrFail();

    expect($rule->payment_mode->value)->toBe('commission');
    expect($rule->salary_amount)->toBeNull();
    expect($rule->salary_period)->toBeNull();
    expect((float) $rule->amount)->toBe(8.0);
});

test('a confirmation agent missing amount fields for commission mode is rejected', function () {
    [$admin] = makeAdminWithStoreAndProduct();

    $response = $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'Bad Agent',
        'email' => 'bad-agent@example.com',
        'role' => 'confirmation_agent',
        'status' => 'active',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'payment_mode' => 'commission',
    ]);

    $response->assertSessionHasErrors(['trigger_status', 'amount_type', 'amount']);
    expect(User::where('email', 'bad-agent@example.com')->exists())->toBeFalse();
});

test('the commission entries page renders an empty state for an admin with no entries yet', function () {
    [$admin] = makeAdminWithStoreAndProduct();

    $response = $this->actingAs($admin)->get(route('commission-entries.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('commission-entries/index')
        ->where('isAdmin', true)
        ->has('entries.data', 0)
        ->has('invoices.data', 0)
    );
});

test('an agent sees only their own commission entries, read-only', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create([
        'business_id' => $admin->business_id,
        'role' => UserRole::CONFIRMATION_AGENT,
        'status' => UserStatus::ACTIVE,
    ]);

    $response = $this->actingAs($agent)->get(route('commission-entries.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('commission-entries/index')
        ->where('isAdmin', false)
        ->has('entries.data', 0)
    );
});
