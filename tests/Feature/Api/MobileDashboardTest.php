<?php

use App\Enums\OrderConfirmationStatus;
use App\Models\CommissionLedgerEntry;
use App\Models\DailyStatsSummary;
use App\Models\PerformanceTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function makeAgentStatRow(int $businessId, int $agentId, string $date, array $overrides = []): DailyStatsSummary
{
    return DailyStatsSummary::create([
        'business_id' => $businessId,
        'agent_id' => $agentId,
        'store_id' => null,
        'product_id' => null,
        'stat_date' => $date,
        ...$overrides,
    ]);
}

test('the dashboard reports all-time handled, confirmed and rate for the authenticated agent', function () {
    [$user, $token] = actingAsConfirmationAgent();

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 20,
        'confirmed_count' => 13,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('totals.handled'))->toBe(20);
    expect($response->json('totals.confirmed'))->toBe(13);
    // Loose compare: a whole-number rate serialises as 65, not 65.0.
    expect($response->json('totals.confirmation_rate'))->toEqual(65);
});

test('the totals span every day on record, not just today', function () {
    [$user, $token] = actingAsConfirmationAgent();

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 4,
    ]);
    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->subDays(30)->toDateString(), [
        'orders_count' => 20,
        'confirmed_count' => 11,
    ]);
    // Well outside any trailing window the old dashboard used.
    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->subDays(400)->toDateString(), [
        'orders_count' => 70,
        'confirmed_count' => 45,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('totals.handled'))->toBe(100);
    expect($response->json('totals.confirmed'))->toBe(60);
    expect($response->json('totals.confirmation_rate'))->toEqual(60);
});

test('the rate is weighted by volume rather than averaging each day\'s own rate', function () {
    [$user, $token] = actingAsConfirmationAgent();

    // A 100%% day of one order must not outweigh a 50%% day of eighty.
    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 1,
        'confirmed_count' => 1,
    ]);
    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->subDay()->toDateString(), [
        'orders_count' => 80,
        'confirmed_count' => 40,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    // 41/81, not the 75%% an unweighted average of the two days would give.
    expect($response->json('totals.confirmation_rate'))->toBe(50.6);
});

test('an agent who has handled nothing reports a null rate, not zero percent', function () {
    [, $token] = actingAsConfirmationAgent();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('totals.handled'))->toBe(0);
    // Null is "nothing handled yet", which is a different fact from a 0% rate.
    expect($response->json('totals.confirmation_rate'))->toBeNull();
});

test('another agent\'s stats never leak into the dashboard', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherAgent = makeBusinessUser(['business_id' => $user->business_id, 'role' => 'confirmation_agent']);

    makeAgentStatRow($user->business_id, $otherAgent->id, Carbon::today()->toDateString(), [
        'orders_count' => 50,
        'confirmed_count' => 50,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('totals.handled'))->toBe(0);
});

test('business-wide and per-store rows are excluded so orders are not counted twice', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $store = makeStore($user->business_id);
    $date = Carbon::today()->toDateString();

    // The same orders are recorded three ways by IncrementsDailyStats:
    // business-wide, per-store, and per-agent. Only the last is this
    // agent's own total.
    makeAgentStatRow($user->business_id, $user->id, $date, ['orders_count' => 10, 'confirmed_count' => 6]);
    DailyStatsSummary::create([
        'business_id' => $user->business_id,
        'agent_id' => null,
        'store_id' => null,
        'product_id' => null,
        'stat_date' => $date,
        'orders_count' => 40,
        'confirmed_count' => 25,
    ]);
    DailyStatsSummary::create([
        'business_id' => $user->business_id,
        'agent_id' => null,
        'store_id' => $store->id,
        'product_id' => null,
        'stat_date' => $date,
        'orders_count' => 40,
        'confirmed_count' => 25,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('totals.handled'))->toBe(10);
});

test('the queue counts mirror the leads screen\'s own buckets', function () {
    [$user, $token] = actingAsConfirmationAgent();

    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::NEW]);
    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::ASSIGNED]);
    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::CALLBACK]);
    makeOrder($user->business_id, ['assigned_agent_id' => $user->id, 'confirmation_status' => OrderConfirmationStatus::CONFIRMED]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('queue.new'))->toBe(2);
    expect($response->json('queue.follow_up'))->toBe(1);
    expect($response->json('queue.confirmed'))->toBe(1);
});

test('the queue counts exclude orders assigned to another agent', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherAgent = makeBusinessUser(['business_id' => $user->business_id, 'role' => 'confirmation_agent']);

    makeOrder($user->business_id, ['assigned_agent_id' => $otherAgent->id, 'confirmation_status' => OrderConfirmationStatus::NEW]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('queue.new'))->toBe(0);
});

test('earnings come from the agent\'s own commission entries', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherAgent = makeBusinessUser(['business_id' => $user->business_id, 'role' => 'confirmation_agent']);
    $order = makeOrder($user->business_id, ['assigned_agent_id' => $user->id]);

    CommissionLedgerEntry::create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'order_id' => $order->id,
        'amount' => 120.50,
        'entry_type' => 'confirmation',
    ]);
    CommissionLedgerEntry::create([
        'business_id' => $user->business_id,
        'user_id' => $otherAgent->id,
        'order_id' => $order->id,
        'amount' => 999.00,
        'entry_type' => 'confirmation',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('totals.earnings'))->toBe(120.5);
});

test('an agent below their confirmation-rate target gets a warning', function () {
    [$user, $token] = actingAsConfirmationAgent();

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 5,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('warning.metric'))->toBe('confirmation_rate');
    expect($response->json('warning.actual_rate'))->toEqual(50);
    expect($response->json('warning.target_rate'))->toEqual(80);
});

test('an agent meeting their target gets no warning', function () {
    [$user, $token] = actingAsConfirmationAgent();

    PerformanceTarget::factory()->confirmationRate(50)->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 9,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('warning'))->toBeNull();
});

test('an agent below the volume needed for evaluation is not warned', function () {
    [$user, $token] = actingAsConfirmationAgent();

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'min_orders_for_evaluation' => 20,
    ]);

    // A 0% rate, but off just three orders — not evidence of anything, and
    // warning on it would teach agents to ignore the banner.
    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 3,
        'confirmed_count' => 0,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('warning'))->toBeNull();
});

test('an agent-specific target overrides the business-wide default', function () {
    [$user, $token] = actingAsConfirmationAgent();

    // Business default would flag this agent; their own target would not.
    PerformanceTarget::factory()->confirmationRate(90)->create([
        'business_id' => $user->business_id,
        'user_id' => null,
        'min_orders_for_evaluation' => 5,
    ]);
    PerformanceTarget::factory()->confirmationRate(40)->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 6,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('warning'))->toBeNull();
});

test('an inactive target never warns', function () {
    [$user, $token] = actingAsConfirmationAgent();

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'min_orders_for_evaluation' => 5,
        'is_active' => false,
    ]);

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 1,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('warning'))->toBeNull();
});

test('another business\'s target never applies to this agent', function () {
    [$user, $token] = actingAsConfirmationAgent();
    $otherUser = makeBusinessUser();

    PerformanceTarget::factory()->confirmationRate(95)->create([
        'business_id' => $otherUser->business_id,
        'user_id' => null,
        'min_orders_for_evaluation' => 1,
    ]);

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 1,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('warning'))->toBeNull();
});

test('the dashboard rejects a request with no token', function () {
    $this->getJson(route('api.mobile.dashboard'))->assertUnauthorized();
});

test('the dashboard reports progress toward an active target the agent is meeting', function () {
    [$user, $token] = actingAsConfirmationAgent();

    PerformanceTarget::factory()->confirmationRate(50)->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 8,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    // Unlike the warning, progress is reported whether or not they're below.
    expect($response->json('target.actual_rate'))->toEqual(80);
    expect($response->json('target.target_rate'))->toEqual(50);
    expect($response->json('target.is_below'))->toBeFalse();
    expect($response->json('target.orders_to_target'))->toBeNull();
});

test('an agent below target is told how many more orders to convert', function () {
    [$user, $token] = actingAsConfirmationAgent();

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 5,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('target.is_below'))->toBeTrue();
    // 5/10 at 80%: 15 straight conversions gives exactly 20/25 = 80%.
    expect($response->json('target.orders_to_target'))->toBe(15);
});

test('the orders-to-target figure is the smallest run that reaches the target', function () {
    [$user, $token] = actingAsConfirmationAgent();

    PerformanceTarget::factory()->confirmationRate(50)->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'min_orders_for_evaluation' => 5,
    ]);

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 0,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    // 0/10 at 50%: 10 gives exactly 10/20, and 9 would only reach 47.4%.
    expect($response->json('target.orders_to_target'))->toBe(10);
});

test('an agent below the volume needed for evaluation gets no target progress', function () {
    [$user, $token] = actingAsConfirmationAgent();

    PerformanceTarget::factory()->confirmationRate(80)->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'min_orders_for_evaluation' => 50,
    ]);

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 5,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('target'))->toBeNull();
});

test('an agent with no active target gets no target progress', function () {
    [$user, $token] = actingAsConfirmationAgent();

    makeAgentStatRow($user->business_id, $user->id, Carbon::today()->toDateString(), [
        'orders_count' => 10,
        'confirmed_count' => 5,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.dashboard'));

    $response->assertOk();
    expect($response->json('target'))->toBeNull();
});
