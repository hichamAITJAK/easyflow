<?php

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\PerformanceTarget;
use App\Services\Operations\Performance\AgentPerformanceEvaluator;
use App\Services\Operations\Performance\PerformanceTargetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a newly created business gets business-wide targets', function () {
    // Drives the seeder directly rather than through self-registration:
    // signup is closed, so business creation is the only path that still
    // reaches this.
    $business = Business::factory()->create(['name' => 'Fresh Business']);
    app(PerformanceTargetSeeder::class)->seed($business);

    $targets = PerformanceTarget::where('business_id', $business->id)
        ->whereNull('user_id')
        ->get()
        ->keyBy(fn (PerformanceTarget $t) => $t->metric->value);

    expect($targets)->toHaveCount(2);
    expect((float) $targets['confirmation_rate']->target_percentage)
        ->toBe((float) config('performance.defaults.confirmation_rate'));
    expect((float) $targets['delivery_success_rate']->target_percentage)
        ->toBe((float) config('performance.defaults.delivery_success_rate'));
    expect($targets['confirmation_rate']->user_id)->toBeNull();
    expect($targets['confirmation_rate']->is_active)->toBeTrue();
});

test('an agent with no targets of their own is measured against the business defaults', function () {
    $admin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($admin->business);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    // Before this change targetsFor() returned an empty array, so the agent
    // was never evaluated at all.
    $targets = app(AgentPerformanceEvaluator::class)->targetsFor($agent);

    expect($targets)->toHaveCount(2);
    expect(collect($targets)->map(fn (PerformanceTarget $t) => $t->metric->value)->sort()->values()->all())
        ->toBe(['confirmation_rate', 'delivery_success_rate']);
});

test('an agent-specific target overrides the business default', function () {
    $admin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($admin->business);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    PerformanceTarget::create([
        'business_id' => $agent->business_id,
        'user_id' => $agent->id,
        'metric' => 'confirmation_rate',
        'target_percentage' => 55,
        'period' => 'weekly',
    ]);

    $targets = collect(app(AgentPerformanceEvaluator::class)->targetsFor($agent))
        ->keyBy(fn (PerformanceTarget $t) => $t->metric->value);

    expect((float) $targets['confirmation_rate']->target_percentage)->toBe(55.0);
    expect((float) $targets['delivery_success_rate']->target_percentage)
        ->toBe((float) config('performance.defaults.delivery_success_rate'));
});

test('seeding is idempotent and never overwrites a tuned value', function () {
    $admin = makeBusinessUser();
    $seeder = app(PerformanceTargetSeeder::class);

    expect($seeder->seed($admin->business))->toBe(2);

    PerformanceTarget::where('business_id', $admin->business_id)
        ->whereNull('user_id')
        ->where('metric', 'confirmation_rate')
        ->update(['target_percentage' => 65]);

    expect($seeder->seed($admin->business->fresh()))->toBe(0);

    expect(PerformanceTarget::where('business_id', $admin->business_id)->whereNull('user_id')->count())->toBe(2);
    expect((float) PerformanceTarget::where('business_id', $admin->business_id)
        ->whereNull('user_id')
        ->where('metric', 'confirmation_rate')
        ->value('target_percentage'))->toBe(65.0);
});

test('the agent form is told the business default, not a hardcoded literal', function () {
    $admin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($admin->business);

    PerformanceTarget::where('business_id', $admin->business_id)
        ->whereNull('user_id')
        ->where('metric', 'confirmation_rate')
        ->update(['target_percentage' => 62]);

    $this->actingAs($admin)
        ->get(route('users.create'))
        ->assertInertia(fn ($page) => $page
            ->component('users/create')
            ->where('performanceDefaults.confirmation_rate', 62)
            ->etc()
        );
});

test('the business period and bonus are saved and inherited by agents', function () {
    $admin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($admin->business);

    $this->actingAs($admin)->patch(route('business.update'), [
        'targets' => [
            'confirmation_rate' => 85,
            'delivery_success_rate' => 90,
            'confirmation_rate_period' => 'monthly',
            'delivery_success_rate_period' => 'daily',
            'confirmation_rate_bonus' => 500,
            'delivery_success_rate_bonus' => '',
        ],
        'min_orders_for_evaluation' => 20,
    ])->assertRedirect();

    $rows = PerformanceTarget::where('business_id', $admin->business_id)
        ->whereNull('user_id')
        ->get()
        ->keyBy(fn (PerformanceTarget $t) => $t->metric->value);

    // period is no longer frozen at weekly.
    expect($rows['confirmation_rate']->period->value)->toBe('monthly');
    expect($rows['delivery_success_rate']->period->value)->toBe('daily');

    expect((float) $rows['confirmation_rate']->bonus_amount)->toBe(500.0);
    // Blank clears the bonus rather than storing 0.
    expect($rows['delivery_success_rate']->bonus_amount)->toBeNull();
});

test('an agent can be judged on a different window than the business', function () {
    $admin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($admin->business);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($admin)->patch(route('users.update', $agent), [
        'name' => $agent->name,
        'email' => $agent->email,
        'role' => 'confirmation_agent',
        'status' => 'active',
        'payment_mode' => 'salary',
        'salary_amount' => '3000',
        'salary_period' => 'monthly',
        'targets' => [
            [
                'metric' => 'confirmation_rate',
                'target_percentage' => 88,
                'period' => 'monthly',
                'bonus_amount' => 750,
            ],
        ],
    ])->assertRedirect();

    $target = PerformanceTarget::where('user_id', $agent->id)->firstOrFail();

    expect($target->period->value)->toBe('monthly');
    expect((float) $target->bonus_amount)->toBe(750.0);

    // And the evaluator sizes its window from that row, not a constant.
    expect($target->period->days())->toBe(30);
});

test('an agent target with no period inherits the business window', function () {
    $admin = makeBusinessUser();
    app(PerformanceTargetSeeder::class)->seed($admin->business);

    PerformanceTarget::where('business_id', $admin->business_id)
        ->whereNull('user_id')
        ->where('metric', 'confirmation_rate')
        ->update(['period' => 'monthly']);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($admin)->patch(route('users.update', $agent), [
        'name' => $agent->name,
        'email' => $agent->email,
        'role' => 'confirmation_agent',
        'status' => 'active',
        'payment_mode' => 'salary',
        'salary_amount' => '3000',
        'salary_period' => 'monthly',
        'targets' => [
            ['metric' => 'confirmation_rate', 'target_percentage' => 88],
        ],
    ])->assertRedirect();

    // Not the hardcoded 'weekly' it used to be.
    expect(PerformanceTarget::where('user_id', $agent->id)->first()->period->value)->toBe('monthly');
});
