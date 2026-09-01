<?php

use App\Enums\UserRole;
use App\Models\PerformanceTarget;
use App\Services\Operations\Performance\AgentPerformanceEvaluator;
use App\Services\Operations\Performance\PerformanceTargetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('an admin sees the business targets currently in effect', function () {
    $admin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($admin->business);

    PerformanceTarget::where('business_id', $admin->business_id)
        ->whereNull('user_id')
        ->where('metric', 'confirmation_rate')
        ->update(['target_percentage' => 72]);

    $this->actingAs($admin)
        ->get(route('business.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/business')
            ->where('targets.confirmation_rate', 72)
            ->where('targets.delivery_success_rate', 90)
            ->has('minOrdersForEvaluation')
        );
});

test('saving updates the business-wide rows and takes effect for agents', function () {
    $admin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($admin->business);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($admin)->patch(route('business.update'), [
        'targets' => [
            'confirmation_rate' => 65,
            'delivery_success_rate' => 85,
            'confirmation_rate_period' => 'monthly',
            'delivery_success_rate_period' => 'weekly',
            'confirmation_rate_bonus' => 500,
            'delivery_success_rate_bonus' => '',
        ],
        'min_orders_for_evaluation' => 25,
    ])->assertRedirect();

    $rows = PerformanceTarget::where('business_id', $admin->business_id)
        ->whereNull('user_id')
        ->get()
        ->keyBy(fn (PerformanceTarget $t) => $t->metric->value);

    expect((float) $rows['confirmation_rate']->target_percentage)->toBe(65.0);
    expect((float) $rows['delivery_success_rate']->target_percentage)->toBe(85.0);
    expect($rows['confirmation_rate']->min_orders_for_evaluation)->toBe(25);

    // The agent with no override is now measured against the new values.
    $targets = collect(app(AgentPerformanceEvaluator::class)->targetsFor($agent))
        ->keyBy(fn (PerformanceTarget $t) => $t->metric->value);

    expect((float) $targets['confirmation_rate']->target_percentage)->toBe(65.0);
});

test('saving works for a business that has no target rows yet', function () {
    $admin = makeBusinessUser();

    expect(PerformanceTarget::where('business_id', $admin->business_id)->count())->toBe(0);

    $this->actingAs($admin)->patch(route('business.update'), [
        'targets' => [
            'confirmation_rate' => 70,
            'delivery_success_rate' => 80,
            'confirmation_rate_period' => 'weekly',
            'delivery_success_rate_period' => 'weekly',
        ],
        'min_orders_for_evaluation' => 10,
    ])->assertRedirect();

    expect(PerformanceTarget::where('business_id', $admin->business_id)->whereNull('user_id')->count())->toBe(2);
});

test('an agent-specific override is not touched by a business-wide save', function () {
    $admin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($admin->business);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $override = PerformanceTarget::create([
        'business_id' => $agent->business_id,
        'user_id' => $agent->id,
        'metric' => 'confirmation_rate',
        'target_percentage' => 55,
        'period' => 'weekly',
    ]);

    $this->actingAs($admin)->patch(route('business.update'), [
        'targets' => [
            'confirmation_rate' => 65,
            'delivery_success_rate' => 85,
            'confirmation_rate_period' => 'monthly',
            'delivery_success_rate_period' => 'weekly',
            'confirmation_rate_bonus' => 500,
            'delivery_success_rate_bonus' => '',
        ],
        'min_orders_for_evaluation' => 25,
    ])->assertRedirect();

    expect((float) $override->refresh()->target_percentage)->toBe(55.0);
});

test('out of range values are rejected', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->patch(route('business.update'), [
        'targets' => [
            'confirmation_rate' => 150,
            'delivery_success_rate' => 0,
            'confirmation_rate_period' => 'weekly',
            'delivery_success_rate_period' => 'weekly',
        ],
        'min_orders_for_evaluation' => 0,
    ])->assertSessionHasErrors([
        'targets.confirmation_rate',
        'targets.delivery_success_rate',
        'min_orders_for_evaluation',
    ]);

    expect(PerformanceTarget::where('business_id', $admin->business_id)->count())->toBe(0);
});

test('a confirmation agent cannot view or change business settings', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    $this->actingAs($agent)->get(route('business.edit'))->assertForbidden();

    $this->actingAs($agent)->patch(route('business.update'), [
        'targets' => [
            'confirmation_rate' => 10,
            'delivery_success_rate' => 10,
            'confirmation_rate_period' => 'weekly',
            'delivery_success_rate_period' => 'weekly',
        ],
        'min_orders_for_evaluation' => 1,
    ])->assertForbidden();

    expect(PerformanceTarget::withoutGlobalScopes()->count())->toBe(0);
});

test('a save cannot reach another business', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($otherAdmin->business);

    $this->actingAs($admin)->patch(route('business.update'), [
        'targets' => [
            'confirmation_rate' => 65,
            'delivery_success_rate' => 85,
            'confirmation_rate_period' => 'monthly',
            'delivery_success_rate_period' => 'weekly',
            'confirmation_rate_bonus' => 500,
            'delivery_success_rate_bonus' => '',
        ],
        'min_orders_for_evaluation' => 25,
    ])->assertRedirect();

    // business_id comes from the authenticated user, never from input.
    $other = PerformanceTarget::withoutGlobalScopes()
        ->where('business_id', $otherAdmin->business_id)
        ->whereNull('user_id')
        ->where('metric', 'confirmation_rate')
        ->first();

    expect((float) $other->target_percentage)
        ->toBe((float) config('performance.defaults.confirmation_rate'));
});

test('an admin updates their business identity details', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => 'EasyFlow Store',
        'legal_name' => 'EasyFlow SARL',
        'ice' => '001234567000089',
        'rc' => 'RC-12345',
        'if_number' => 'IF-98765',
        'phone' => '+212 612-345-678',
        'email' => 'contact@easyflow.ma',
        'address' => '12 Rue Hassan II',
        'city' => 'Casablanca',
    ])->assertRedirect();

    $business = $admin->business->fresh();

    expect($business->name)->toBe('EasyFlow Store');
    expect($business->legal_name)->toBe('EasyFlow SARL');
    expect($business->ice)->toBe('001234567000089');
    // Normalised through PhoneNumber::format, same as user profiles.
    expect($business->phone)->toBe('0612345678');
    expect($business->city)->toBe('Casablanca');
});

test('the business edit page exposes the current details', function () {
    $admin = makeBusinessUser();
    $admin->business->update(['ice' => '001234567000089', 'city' => 'Rabat']);

    $this->actingAs($admin)
        ->get(route('business.edit'))
        ->assertInertia(fn ($page) => $page
            ->component('settings/business')
            ->where('business.ice', '001234567000089')
            ->where('business.city', 'Rabat')
            // The slug identifies the business and stays super-admin-only.
            ->missing('business.slug')
        );
});

test('a logo upload is stored and resolves to a public url', function () {
    Storage::fake('public');

    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => $admin->business->name,
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertRedirect();

    $business = $admin->business->fresh();
    $stored = $business->getRawOriginal('logo');

    expect($stored)->not->toBeNull();
    Storage::disk('public')->assertExists($stored);
    // The accessor hands the frontend a URL, not a disk path.
    expect($business->logo)->toBe('/storage/'.$stored);
});

test('removing a logo deletes the stored file', function () {
    Storage::fake('public');

    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => $admin->business->name,
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $stored = $admin->business->fresh()->getRawOriginal('logo');

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => $admin->business->name,
        'remove_logo' => '1',
    ])->assertRedirect();

    expect($admin->business->fresh()->logo)->toBeNull();
    Storage::disk('public')->assertMissing($stored);
});

test('an agent cannot change business details', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);
    $originalName = $agent->business->name;

    $this->actingAs($agent)->post(route('business.profile.update'), [
        'name' => 'Hijacked',
    ])->assertForbidden();

    expect($agent->business->fresh()->name)->toBe($originalName);
});

test('business details validation rejects a bad email and oversized logo', function () {
    Storage::fake('public');

    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('business.profile.update'), [
        'name' => '',
        'email' => 'not-an-email',
        'logo' => UploadedFile::fake()->create('huge.png', 4096, 'image/png'),
    ])->assertSessionHasErrors(['name', 'email', 'logo']);
});
