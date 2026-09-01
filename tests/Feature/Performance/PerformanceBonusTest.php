<?php

use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\DailyStatsSummary;
use App\Models\PerformanceTarget;
use App\Models\User;
use App\Services\Operations\Commissions\CommissionService;
use App\Services\Operations\Performance\AgentPerformanceEvaluator;
use App\Services\Operations\Performance\PerformanceBonusAwarder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** An agent whose stats clear any sane target. */
function agentMeetingTarget(int $businessId, int $orders = 100, int $confirmed = 95): User
{
    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $businessId,
    ]);

    DailyStatsSummary::create([
        'business_id' => $businessId,
        'agent_id' => $agent->id,
        'stat_date' => Carbon::today(),
        'orders_count' => $orders,
        'confirmed_count' => $confirmed,
        'submitted_to_courier_count' => $orders,
        'delivered_count' => $confirmed,
    ]);

    return $agent;
}

function bonusTarget(User $agent, array $overrides = []): PerformanceTarget
{
    return PerformanceTarget::create([
        'business_id' => $agent->business_id,
        'user_id' => $agent->id,
        'metric' => 'confirmation_rate',
        'target_percentage' => 80,
        'bonus_amount' => 500,
        'period' => 'monthly',
        'min_orders_for_evaluation' => 10,
        'is_active' => true,
        ...$overrides,
    ]);
}

test('an agent meeting a target with a bonus is paid it once', function () {
    $admin = makeBusinessUser();
    $agent = agentMeetingTarget($admin->business_id);
    $target = bonusTarget($agent);

    $entry = app(PerformanceBonusAwarder::class)->award($agent, $target);

    expect($entry)->not->toBeNull();
    expect((float) $entry->amount)->toBe(500.0);
    expect($entry->entry_type)->toBe('bonus');
    // A bonus belongs to a period, not an order.
    expect($entry->order_id)->toBeNull();
    expect($entry->period_start->toDateString())->toBe(Carbon::today()->startOfMonth()->toDateString());
});

test('a bonus is never earned twice for the same period', function () {
    $admin = makeBusinessUser();
    $agent = agentMeetingTarget($admin->business_id);
    $target = bonusTarget($agent);

    $awarder = app(PerformanceBonusAwarder::class);

    // Ten runs on the same day.
    for ($i = 0; $i < 10; $i++) {
        $awarder->award($agent, $target);
    }

    expect(CommissionLedgerEntry::where('user_id', $agent->id)->count())->toBe(1);
});

test('running the command every day of a month awards one bonus, not thirty', function () {
    $admin = makeBusinessUser();
    $agent = agentMeetingTarget($admin->business_id);
    bonusTarget($agent);

    // The window is snapped to the calendar, so every run inside the same
    // month resolves to one period_start — a rolling window would not.
    $start = Carbon::today()->startOfMonth();

    for ($day = 0; $day < 28; $day++) {
        Carbon::setTestNow($start->copy()->addDays($day));
        $this->artisan('agents:award-bonuses')->assertSuccessful();
    }

    Carbon::setTestNow();

    expect(CommissionLedgerEntry::where('user_id', $agent->id)->count())->toBe(1);
});

test('a new calendar period earns a fresh bonus', function () {
    $admin = makeBusinessUser();
    $agent = agentMeetingTarget($admin->business_id);
    $target = bonusTarget($agent);

    $awarder = app(PerformanceBonusAwarder::class);
    $awarder->award($agent, $target);

    // Next month, with fresh stats in that window.
    $nextMonth = Carbon::today()->startOfMonth()->addMonthNoOverflow();

    DailyStatsSummary::create([
        'business_id' => $admin->business_id,
        'agent_id' => $agent->id,
        'stat_date' => $nextMonth,
        'orders_count' => 100,
        'confirmed_count' => 95,
        'submitted_to_courier_count' => 100,
        'delivered_count' => 95,
    ]);

    Carbon::setTestNow($nextMonth);
    $second = $awarder->award($agent, $target);
    Carbon::setTestNow();

    expect($second)->not->toBeNull();
    expect(CommissionLedgerEntry::where('user_id', $agent->id)->count())->toBe(2);
});

test('an agent below target earns nothing', function () {
    $admin = makeBusinessUser();
    $agent = agentMeetingTarget($admin->business_id, orders: 100, confirmed: 40);
    $target = bonusTarget($agent);

    expect(app(PerformanceBonusAwarder::class)->award($agent, $target))->toBeNull();
    expect(CommissionLedgerEntry::where('user_id', $agent->id)->count())->toBe(0);
});

test('an agent below the evaluation threshold earns nothing', function () {
    $admin = makeBusinessUser();
    // Meets the rate, but on too few orders to judge.
    $agent = agentMeetingTarget($admin->business_id, orders: 3, confirmed: 3);
    $target = bonusTarget($agent);

    expect(app(PerformanceBonusAwarder::class)->award($agent, $target))->toBeNull();
});

test('a target with no bonus pays nothing', function () {
    $admin = makeBusinessUser();
    $agent = agentMeetingTarget($admin->business_id);
    $target = bonusTarget($agent, ['bonus_amount' => null]);

    expect(app(PerformanceBonusAwarder::class)->award($agent, $target))->toBeNull();
    expect(CommissionLedgerEntry::where('user_id', $agent->id)->count())->toBe(0);
});

test('the bonus flows into the agent invoice total', function () {
    $admin = makeBusinessUser();
    $agent = agentMeetingTarget($admin->business_id);
    $target = bonusTarget($agent);

    $entry = app(PerformanceBonusAwarder::class)->award($agent, $target);

    $invoice = app(CommissionService::class)
        ->generateInvoice($admin->business_id, $agent->id, [$entry->id]);

    // The payoff of using the ledger: invoicing needed no changes.
    expect((float) $invoice->total_amount)->toBe(500.0);
    expect($entry->fresh()->invoice_id)->toBe($invoice->id);
});

test('the database rejects a duplicate bonus even if the service check is bypassed', function () {
    $admin = makeBusinessUser();
    $agent = agentMeetingTarget($admin->business_id);
    $target = bonusTarget($agent);

    app(PerformanceBonusAwarder::class)->award($agent, $target);

    // Writing straight past the service: the unique index is the guarantee
    // that actually holds under concurrency.
    $duplicate = fn () => CommissionLedgerEntry::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'order_id' => null,
        'performance_target_id' => $target->id,
        'amount' => 500,
        'period_start' => Carbon::today()->startOfMonth(),
        'period_end' => Carbon::today()->endOfMonth(),
        'entry_type' => 'bonus',
    ]);

    expect($duplicate)->toThrow(QueryException::class);
    expect(CommissionLedgerEntry::where('user_id', $agent->id)->count())->toBe(1);
});

test('the warning check and the bonus awarder cover the same agents', function () {
    $admin = makeBusinessUser();

    // Agent A has their own target; agent B falls back to the business-wide
    // one. The precedence rule that decides this used to be duplicated in
    // both commands — this asserts the single shared implementation applies
    // identically to each.
    $agentA = agentMeetingTarget($admin->business_id);
    $agentB = agentMeetingTarget($admin->business_id);

    $businessWide = PerformanceTarget::create([
        'business_id' => $admin->business_id,
        'user_id' => null,
        'metric' => 'confirmation_rate',
        'target_percentage' => 80,
        'bonus_amount' => 300,
        'period' => 'monthly',
        'min_orders_for_evaluation' => 10,
        'is_active' => true,
    ]);

    $agentSpecific = bonusTarget($agentA, ['bonus_amount' => 900]);

    $evaluator = app(AgentPerformanceEvaluator::class);

    // The business-wide target skips agent A, who has their own row.
    expect($evaluator->agentsForTarget($businessWide)->pluck('id')->all())
        ->toBe([$agentB->id]);

    expect($evaluator->agentsForTarget($agentSpecific)->pluck('id')->all())
        ->toBe([$agentA->id]);

    $this->artisan('agents:award-bonuses')->assertSuccessful();

    // Each agent paid once, at the rate of the target that actually applies.
    expect((float) CommissionLedgerEntry::where('user_id', $agentA->id)->sum('amount'))->toBe(900.0);
    expect((float) CommissionLedgerEntry::where('user_id', $agentB->id)->sum('amount'))->toBe(300.0);
});

test('every surface that lists ledger entries survives a bonus', function () {
    $admin = makeBusinessUser();
    $agent = agentMeetingTarget($admin->business_id);
    $target = bonusTarget($agent);

    $entry = app(PerformanceBonusAwarder::class)->award($agent, $target);

    $invoice = app(CommissionService::class)
        ->generateInvoice($admin->business_id, $agent->id, [$entry->id]);

    // A bonus has no order, so anything reading $entry->order->reference
    // without a null guard 500s here.
    $this->actingAs($admin)
        ->get(route('commission-entries.invoices.show', $invoice))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('commission-entries.index'))
        ->assertOk();

    // The PDF names it "Bonus", not "Commission", and prints the period
    // description in place of an order reference.
    $html = view('pdf.invoice', ['invoice' => $invoice->fresh()])->render();

    expect($html)->toContain('Bonus');
    expect($html)->toContain('Confirmation rate bonus');
    // Never a bare "#" from a null order_id.
    expect($html)->not->toContain('>#<');
});
