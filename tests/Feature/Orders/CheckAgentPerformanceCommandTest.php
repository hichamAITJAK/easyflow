<?php

use App\Models\DailyStatsSummary;
use App\Models\PerformanceTarget;
use App\Notifications\PerformanceWarningNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function makeDailyStat(int $businessId, ?int $agentId, array $overrides = []): DailyStatsSummary
{
    return DailyStatsSummary::create([
        'business_id' => $businessId,
        'store_id' => null,
        'product_id' => null,
        'agent_id' => $agentId,
        'stat_date' => now()->toDateString(),
        'orders_count' => 0,
        'assigned_count' => 0,
        'confirmed_count' => 0,
        'submitted_to_courier_count' => 0,
        'delivered_count' => 0,
        'returned_count' => 0,
        'cancelled_count' => 0,
        'refused_count' => 0,
        ...$overrides,
    ]);
}

test('an agent below their confirmation_rate target is warned, along with every admin', function () {
    Notification::fake();

    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'admin']);
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeDailyStat($admin->business_id, $agent->id, ['orders_count' => 10, 'confirmed_count' => 5]);

    $this->artisan('agents:check-performance')->assertSuccessful();

    Notification::assertSentTo($agent, PerformanceWarningNotification::class);
    Notification::assertSentTo($admin, PerformanceWarningNotification::class);
    Notification::assertSentTo($otherAdmin, PerformanceWarningNotification::class);
});

test('an agent meeting their target is not warned', function () {
    Notification::fake();

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeDailyStat($admin->business_id, $agent->id, ['orders_count' => 10, 'confirmed_count' => 9]);

    $this->artisan('agents:check-performance')->assertSuccessful();

    Notification::assertNothingSent();
});

test('an agent below the order-volume threshold is skipped, even if their rate is low', function () {
    Notification::fake();

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'min_orders_for_evaluation' => 20,
    ]);

    makeDailyStat($admin->business_id, $agent->id, ['orders_count' => 10, 'confirmed_count' => 1]);

    $this->artisan('agents:check-performance')->assertSuccessful();

    Notification::assertNothingSent();
});

test('an inactive target is not evaluated', function () {
    Notification::fake();

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);

    PerformanceTarget::factory()->confirmationRate(80)->inactive()->create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeDailyStat($admin->business_id, $agent->id, ['orders_count' => 10, 'confirmed_count' => 1]);

    $this->artisan('agents:check-performance')->assertSuccessful();

    Notification::assertNothingSent();
});

test('a business-wide default target applies to every confirmation agent without their own target', function () {
    Notification::fake();

    $admin = makeBusinessUser();
    $agentWithoutTarget = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);
    $agentWithOwnTarget = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $admin->business_id,
        'user_id' => null,
        'min_orders_for_evaluation' => 5,
    ]);

    PerformanceTarget::factory()->confirmationRate(20)->create([
        'business_id' => $admin->business_id,
        'user_id' => $agentWithOwnTarget->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeDailyStat($admin->business_id, $agentWithoutTarget->id, ['orders_count' => 10, 'confirmed_count' => 5]);
    makeDailyStat($admin->business_id, $agentWithOwnTarget->id, ['orders_count' => 10, 'confirmed_count' => 5]);

    $this->artisan('agents:check-performance')->assertSuccessful();

    // 50% is below the 80% business-wide default.
    Notification::assertSentTo($agentWithoutTarget, PerformanceWarningNotification::class);
    // 50% still clears their own, lower 20% target.
    Notification::assertNotSentTo($agentWithOwnTarget, PerformanceWarningNotification::class);
});

test('an agent below their delivery_success_rate target is warned', function () {
    Notification::fake();

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);

    PerformanceTarget::factory()->deliverySuccessRate(80)->create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeDailyStat($admin->business_id, $agent->id, ['submitted_to_courier_count' => 10, 'delivered_count' => 5]);

    $this->artisan('agents:check-performance')->assertSuccessful();

    Notification::assertSentTo($agent, PerformanceWarningNotification::class);
});

test('stats outside the target period window are excluded from the rolling rate', function () {
    Notification::fake();

    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['business_id' => $admin->business_id, 'role' => 'confirmation_agent']);

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'period' => 'daily',
        'min_orders_for_evaluation' => 5,
    ]);

    makeDailyStat($admin->business_id, $agent->id, [
        'stat_date' => now()->subDays(5)->toDateString(),
        'orders_count' => 10,
        'confirmed_count' => 1,
    ]);

    $this->artisan('agents:check-performance')->assertSuccessful();

    Notification::assertNothingSent();
});
