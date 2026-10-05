<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\PerformanceMetric;
use App\Enums\PerformanceTargetPeriod;
use App\Enums\UserRole;
use App\Models\DailyStatsSummary;
use App\Models\PerformanceTarget;
use App\Models\User;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the admin dashboard renders every widget prop with the right shape', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/admin')
        ->has('filters')
        ->has('stores')
        ->has('agents')
        ->has('money', fn ($money) => $money
            ->has('totalEarned')
            ->has('totalEarnedDelta')
            ->has('commissions')
            ->has('commissionsDelta')
            ->has('courierExpected')
            ->has('courierVariance')
        )
        ->has('ordersPerDay', 30)
        ->has('team')
        ->has('targets', fn ($targets) => $targets
            ->has('confirmation')
            ->has('delivery')
        )
        ->has('rates', fn ($rates) => $rates
            ->has('buckets', fn ($buckets) => $buckets
                ->has('confirmation')
                ->has('delivery')
                ->has('return')
            )
            ->has('totals', fn ($totals) => $totals
                ->has('confirmation')
                ->has('delivery')
                ->has('return')
            )
            ->has('counts', fn ($counts) => $counts
                ->has('confirmation')
                ->has('delivery')
                ->has('return')
            )
        )
        ->has('performanceTable', fn ($table) => $table
            ->has('stores')
            ->has('products')
            ->has('couriers')
        )
        ->has('alerts')
    );
});

test('the period preset drives the ordersPerDay window length', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    $response = $this->get(route('dashboard', ['period' => '7d']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('filters.period', '7d')
        ->has('ordersPerDay', 7)
    );
});

test('store and agent filters echo back through the filters prop', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    $response = $this->get(route('dashboard', ['store_ids' => '3,7', 'agent_id' => 888]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('filters.store_ids', '3,7')
        ->where('filters.agent_id', '888')
    );
});

test('real order activity lands in the admin dashboard props', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create([
        'business_id' => $admin->business_id,
        'role' => UserRole::CONFIRMATION_AGENT,
    ]);

    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'assigned',
        'assigned_agent_id' => $agent->id,
        'total_amount' => 500,
        'shipped_at' => now()->subDay(),
    ]);

    $service = app(OrderService::class);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::SUBMITTED_TO_COURIER);
    $service->updateDeliveryStatus($order, OrderDeliveryStatus::DELIVERED);

    $this->actingAs($admin);
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('money.totalEarned', 500)
        ->has('team', 1)
        ->where('team.0.orders', 0)
        ->where('team.0.confirmationRate', 0)
        ->etc()
    );
});

test('the agent dashboard renders every stat/chart prop with the right shape', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);
    $this->actingAs($agent);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/agent')
        ->has('totals')
        ->has('tileRates', fn ($tileRates) => $tileRates
            ->has('confirmed')
            ->has('delivered')
            ->has('cancelled')
        )
        ->has('confirmationRateTrend', 30)
        ->has('confirmationRateTarget')
        ->has('deliveryRateTrend', 30)
        ->has('deliveryRateTarget')
        ->has('rateTotals', fn ($rateTotals) => $rateTotals
            ->has('confirmation')
            ->has('delivery')
        )
        ->has('commissionEarned')
        ->missing('filters')
        ->missing('stores')
    );
});

test('the agent dashboard sends no payload for the charts it no longer renders', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);
    $this->actingAs($agent);

    $response = $this->get(route('dashboard'));

    // These fed the funnel/donut/week-over-week/reason cards that were
    // removed from the agent panel. Each cost extra queries per load, so
    // the point of dropping them is that they stop being computed at all.
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/agent')
        ->missing('ordersPerDay')
        ->missing('confirmationFunnel')
        ->missing('deliveryOutcomeBreakdown')
        ->missing('weekOverWeekConfirmationRate')
        ->missing('cancelReasonBreakdown')
        ->missing('returnReasonBreakdown')
        ->etc()
    );
});

test('rate totals are computed from summed counts, not averaged buckets', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    // Deliberately lopsided volume: a tiny perfect day and a large poor
    // one. Averaging the two days' rates gives (100 + 50) / 2 = 75%.
    // Summing the counts gives 51/101 = 50.5% — the real business rate.
    // The gap between those two numbers is what this test pins down.
    DailyStatsSummary::factory()->create([
        'business_id' => $admin->business_id,
        'stat_date' => today()->subDays(2),
        'orders_count' => 1,
        'confirmed_count' => 1,
    ]);
    DailyStatsSummary::factory()->create([
        'business_id' => $admin->business_id,
        'stat_date' => today()->subDay(),
        'orders_count' => 100,
        'confirmed_count' => 50,
    ]);

    $this->actingAs($admin);
    // 7d so the rows land in separate daily buckets. On the 30d default
    // they'd share one weekly bucket and already be summed, which would
    // make averaging and summing agree and leave this test proving
    // nothing.
    $response = $this->get(route('dashboard', ['period' => '7d']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('rates.totals.confirmation', 50.5)
        ->etc()
    );
});

test('rate totals are null when the window has no volume to divide by', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    // A zero denominator must not render as 0% — "no orders yet" and
    // "every order failed" are different facts.
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('rates.totals.confirmation', null)
        ->where('rates.totals.delivery', null)
        ->where('rates.totals.return', null)
        ->etc()
    );
});

test('the agent delivery rate reads delivered over submitted, summed across the window', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    // Lopsided again: a 1/1 perfect day and a 40/100 poor one. Averaging
    // the two stored per-day rates gives (100 + 40) / 2 = 70%. Summing the
    // counts gives 41/101 = 40.6% — what the agent actually delivered.
    DailyStatsSummary::factory()->create([
        'business_id' => $agent->business_id,
        'agent_id' => $agent->id,
        'stat_date' => today()->subDays(2),
        'submitted_to_courier_count' => 1,
        'delivered_count' => 1,
        'delivery_success_rate' => 100,
    ]);
    DailyStatsSummary::factory()->create([
        'business_id' => $agent->business_id,
        'agent_id' => $agent->id,
        'stat_date' => today()->subDay(),
        'submitted_to_courier_count' => 100,
        'delivered_count' => 40,
        'delivery_success_rate' => 40,
    ]);

    $this->actingAs($agent);

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('rateTotals.delivery', 40.6)
        ->etc()
    );
});

test('agent rate totals are null when nothing shipped', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);
    $this->actingAs($agent);

    // Zero denominator must not read as 0% — a new agent has not failed
    // every delivery, they have made none.
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('rateTotals.confirmation', null)
        ->where('rateTotals.delivery', null)
        ->etc()
    );
});

test('an agent-specific delivery target overrides the business-wide default', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    PerformanceTarget::create([
        'business_id' => $agent->business_id,
        'user_id' => null,
        'metric' => PerformanceMetric::DELIVERY_SUCCESS_RATE,
        'target_percentage' => 90,
        'period' => PerformanceTargetPeriod::WEEKLY,
        'is_active' => true,
    ]);
    PerformanceTarget::create([
        'business_id' => $agent->business_id,
        'user_id' => $agent->id,
        'metric' => PerformanceMetric::DELIVERY_SUCCESS_RATE,
        'target_percentage' => 75,
        'period' => PerformanceTargetPeriod::WEEKLY,
        'is_active' => true,
    ]);

    $this->actingAs($agent);

    // The agent's own row wins, matching the precedence the evaluator and
    // the admin dashboard both apply.
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('deliveryRateTarget', '75.00')
        ->etc()
    );
});

test('stat tile shares each divide by their own denominator', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    // 100 assigned, 80 confirmed, 20 cancelled, but only 50 ever reached a
    // courier and 40 of those landed. Delivered must read 80% (40/50, what
    // shipped) and not 40% (40/100, everything assigned) — the agent is
    // not accountable for parcels that were never shipped.
    DailyStatsSummary::factory()->create([
        'business_id' => $agent->business_id,
        'agent_id' => $agent->id,
        'stat_date' => today()->subDay(),
        'orders_count' => 100,
        'confirmed_count' => 80,
        'cancelled_count' => 20,
        'submitted_to_courier_count' => 50,
        'delivered_count' => 40,
    ]);

    $this->actingAs($agent);

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('tileRates.confirmed', 80)
        ->where('tileRates.delivered', 80)
        ->where('tileRates.cancelled', 20)
        ->etc()
    );
});

test('stat tile shares are null when the agent has been assigned nothing', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);
    $this->actingAs($agent);

    // No denominator renders no chip at all, rather than a 0% that would
    // read as failure on a brand-new agent's first day.
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('tileRates.confirmed', null)
        ->where('tileRates.delivered', null)
        ->where('tileRates.cancelled', null)
        ->etc()
    );
});

test('a custom period window is driven by the date_from/date_to pair', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    $response = $this->get(route('dashboard', [
        'period' => 'custom',
        'date_from' => today()->subDays(4)->toDateString(),
        'date_to' => today()->toDateString(),
    ]));

    $response->assertOk();
    // ordersPerDay carries one point per day in the window, so its length
    // is the window length — 5 days inclusive here, not the 30-day default.
    $response->assertInertia(fn ($page) => $page
        ->has('ordersPerDay', 5)
        ->where('filters.period', 'custom')
        ->where('filters.date_from', today()->subDays(4)->toDateString())
        ->where('filters.date_to', today()->toDateString())
        ->etc()
    );
});

test('a backwards custom range is swapped rather than returning nothing', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    // from after to is a mis-built link, not a request for an empty
    // dashboard — the user visibly meant the span between the two dates.
    $this->get(route('dashboard', [
        'period' => 'custom',
        'date_from' => today()->toDateString(),
        'date_to' => today()->subDays(2)->toDateString(),
    ]))->assertInertia(fn ($page) => $page
        ->has('ordersPerDay', 3)
        ->where('filters.date_from', today()->subDays(2)->toDateString())
        ->where('filters.date_to', today()->toDateString())
        ->etc()
    );
});

test('a custom range is clamped so one link cannot request years of points', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    // Every per-day widget builds a point per day, so an unbounded window
    // would let a hand-edited URL blow up the payload.
    $this->get(route('dashboard', [
        'period' => 'custom',
        'date_from' => today()->subYears(5)->toDateString(),
        'date_to' => today()->toDateString(),
    ]))->assertInertia(fn ($page) => $page
        ->has('ordersPerDay', 366)
        ->etc()
    );
});

test('a custom range never reaches past today', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    // Stats beyond today do not exist, so a future end date is pulled back
    // rather than padding the charts with empty days.
    $this->get(route('dashboard', [
        'period' => 'custom',
        'date_from' => today()->subDays(2)->toDateString(),
        'date_to' => today()->addDays(30)->toDateString(),
    ]))->assertInertia(fn ($page) => $page
        ->has('ordersPerDay', 3)
        ->where('filters.date_to', today()->toDateString())
        ->etc()
    );
});

test('an unusable custom range falls back to the default window', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    // A malformed or half-filled range still has to render a dashboard —
    // the window is a view setting, not something worth erroring over.
    foreach ([
        ['period' => 'custom', 'date_from' => 'not-a-date', 'date_to' => 'also-not'],
        ['period' => 'custom', 'date_from' => today()->subDays(3)->toDateString()],
        ['period' => 'custom'],
    ] as $query) {
        $this->get(route('dashboard', $query))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('ordersPerDay', 30)->etc());
    }
});
