<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\PerformanceMetric;
use App\Enums\PerformanceTargetPeriod;
use App\Enums\UserRole;
use App\Models\CourierSettlement;
use App\Models\DailyStatsSummary;
use App\Models\DeliveryAccount;
use App\Models\DeliveryCourrier;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\PerformanceTarget;
use App\Models\Product;
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

test('the admin dashboard renders every section prop with the right shape', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard/admin')
        ->has('filters')
        ->has('stores')
        ->has('agents')
        ->has('alerts')
        ->has('kpis', fn ($kpis) => $kpis
            ->has('received', fn ($k) => $k->has('value')->has('deltaPct')->has('buckets', 8))
            ->has('inProgress', fn ($k) => $k->has('value')->has('sharePct')->has('deltaPct')->has('agentInitials')->has('agentCount'))
            ->has('confirmed', fn ($k) => $k->has('value')->has('ratePct')->has('deltaPct')->has('of'))
            ->has('delivered', fn ($k) => $k->has('value')->has('ratePct')->has('deltaPct')->has('trend', 8))
            ->has('returned', fn ($k) => $k->has('value')->has('ratePct')->has('deltaPct')->has('buckets', 8))
        )
        ->has('income.buckets', 30)
        ->where('income.grain', 'day')
        ->has('expected', fn ($e) => $e->has('toReceiveMad')->has('deliveredUnpaid')->has('lastSettlement'))
        ->has('parcels', fn ($p) => $p->has('total')->has('stages', 4))
        ->has('performance', fn ($perf) => $perf
            ->has('rangeLabel')
            ->has('daily', 30)
            ->has('ordersLabel')
            ->has('outcomes', fn ($o) => $o->has('conf')->has('deliv')->has('ret')->has('commissionsMad')->has('commissionsSub'))
            ->has('goals', fn ($g) => $g->has('conf')->has('deliv'))
            ->where('view.type', 'team')
            ->has('view.agents')
            ->etc()
        )
        ->has('breakdown', fn ($b) => $b->has('stores')->has('products')->has('couriers'))
    );
});

test('the period preset drives the daily window length', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    $this->get(route('dashboard', ['period' => '7d']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.period', '7d')
            ->has('performance.daily', 7)
            // A short window has fewer slices than days, never more.
            ->has('kpis.received.buckets', 7)
            ->etc()
        );

    $this->get(route('dashboard', ['period' => 'today']))
        ->assertInertia(fn ($page) => $page->has('kpis.received.buckets', 1)->etc());
});

test('store and agent filters echo back through the filters prop', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $this->actingAs($admin);

    $this->get(route('dashboard', ['store_ids' => '3,7', 'agent_id' => $agent->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.store_ids', '3,7')
            ->where('filters.agent_id', (string) $agent->id)
            ->where('performance.view.type', 'agent')
            ->where('performance.view.name', $agent->name)
            ->etc()
        );

    // An id that is not one of this business's agents is ignored.
    $this->get(route('dashboard', ['agent_id' => 888]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.agent_id', null)
            ->where('performance.view.type', 'team')
            ->etc()
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
        ->where('kpis.confirmed.value', 1)
        ->where('kpis.delivered.value', 1)
        ->where('income.buckets.29.amountMad', 500)
        ->where('income.totalMad', 500)
        ->has('performance.view.agents', 1)
        ->etc()
    );
});

test('kpi rates read confirmed over received and delivered over confirmed, summed across the window', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    foreach ([[40, 20, 8, 2], [60, 30, 12, 1]] as $index => [$orders, $confirmed, $delivered, $returned]) {
        DailyStatsSummary::factory()->create([
            'business_id' => $admin->business_id,
            'stat_date' => today()->subDays($index + 1),
            'orders_count' => $orders,
            'confirmed_count' => $confirmed,
            'submitted_to_courier_count' => $confirmed,
            'delivered_count' => $delivered,
            'returned_count' => $returned,
        ]);
    }

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('kpis.received.value', 100)
            ->where('kpis.confirmed.value', 50)
            ->where('kpis.confirmed.ratePct', 50)
            ->where('kpis.confirmed.of', 100)
            ->where('kpis.delivered.value', 20)
            ->where('kpis.delivered.ratePct', 40)
            ->where('kpis.returned.value', 3)
            ->where('kpis.returned.ratePct', 6)
            // No previous window: no delta claimed.
            ->where('kpis.received.deltaPct', null)
            ->where('performance.outcomes.conf', [50, 50])
            ->where('performance.outcomes.deliv', [20, 40])
            ->etc()
        );
});

test('deltas compare against the previous window of the same length', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    DailyStatsSummary::factory()->create([
        'business_id' => $admin->business_id,
        'stat_date' => today()->subDays(2),
        'orders_count' => 30,
    ]);
    DailyStatsSummary::factory()->create([
        'business_id' => $admin->business_id,
        'stat_date' => today()->subDays(10),
        'orders_count' => 20,
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard', ['period' => '7d']))
        ->assertInertia(fn ($page) => $page
            ->where('kpis.received.value', 30)
            ->where('kpis.received.deltaPct', 50)
            ->etc()
        );
});

test('the parcels pipeline counts the period orders by their current stage', function () {
    $admin = makeBusinessUser();
    $businessId = $admin->business_id;

    $stage = fn (string $status, array $extra = []) => makeOrder($businessId, [
        'confirmation_status' => 'submitted_to_courier',
        'delivery_status' => $status,
        ...$extra,
    ]);

    $stage('awaiting_pickup');
    $stage('ready_for_pickup');
    $stage('in_transit');
    $stage('out_for_delivery');
    $stage('delivery_attempt_failed');
    $stage('delivered');
    $stage('refused');
    $stage('return_received');

    // None of these belong to a stage in this business's window.
    $stage('cancelled_at_courier');
    $stage('delivered', ['is_test' => true]);
    makeOrder($businessId);
    makeOrder(makeBusinessUser()->business_id, [
        'confirmation_status' => 'submitted_to_courier',
        'delivery_status' => 'delivered',
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('parcels.total', 8)
            ->where('parcels.stages.0.key', 'ready_to_ship')
            ->where('parcels.stages.0.count', 2)
            ->where('parcels.stages.0.ratePct', 25)
            ->where('parcels.stages.1.count', 3)
            ->where('parcels.stages.2.count', 1)
            ->where('parcels.stages.3.count', 2)
            ->etc()
        );
});

test('the parcels pipeline follows the store filter', function () {
    $admin = makeBusinessUser();
    $store = makeStore($admin->business_id);
    $other = makeStore($admin->business_id);

    foreach ([$store, $store, $other] as $owner) {
        makeOrder($admin->business_id, [
            'store_id' => $owner->id,
            'confirmation_status' => 'submitted_to_courier',
            'delivery_status' => 'delivered',
        ]);
    }

    $this->actingAs($admin)
        ->get(route('dashboard', ['store_ids' => (string) $store->id]))
        ->assertInertia(fn ($page) => $page
            ->where('parcels.total', 2)
            ->where('parcels.stages.2.count', 2)
            ->etc()
        );
});

test('in-progress counts the orders still with an agent and who holds them', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create([
        'business_id' => $admin->business_id,
        'role' => UserRole::CONFIRMATION_AGENT,
        'name' => 'Aya El Mansouri',
    ]);

    makeOrder($admin->business_id, ['confirmation_status' => 'assigned', 'assigned_agent_id' => $agent->id]);
    makeOrder($admin->business_id, ['confirmation_status' => 'callback', 'assigned_agent_id' => $agent->id]);
    makeOrder($admin->business_id, ['confirmation_status' => 'confirmed', 'assigned_agent_id' => $agent->id]);
    // Over a day old: also raises the stale alert.
    makeOrder($admin->business_id, ['confirmation_status' => 'assigned', 'assigned_agent_id' => $agent->id])
        ->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('kpis.inProgress.value', 3)
            ->where('kpis.inProgress.agentCount', 1)
            ->where('kpis.inProgress.agentInitials', ['AE'])
            ->where('alerts.0.kind', 'stale_in_progress')
            ->where('alerts.0.strong', '1 order')
            ->etc()
        );
});

test('a product delivering under half of its confirmed orders raises the low-delivery alert', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $product = Product::factory()->create(['business_id' => $admin->business_id, 'name' => 'Posture Corrector Pro']);

    DailyStatsSummary::factory()->create([
        'business_id' => $admin->business_id,
        'product_id' => $product->id,
        'stat_date' => today()->subDay(),
        'orders_count' => 40,
        'confirmed_count' => 25,
        'delivered_count' => 10,
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('alerts.0.kind', 'low_delivery_product')
            ->where('alerts.0.strong', 'Posture Corrector Pro')
            ->where('breakdown.products.0.conf', [25, 62.5])
            ->where('breakdown.products.0.deliv', [10, 40])
            ->etc()
        );
});

test('goal attainment averages each rate over its goal and grades it', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::CONFIRMATION_AGENT]);

    // conf 80/80 = 1.0, deliv 72.5/90 = 0.81 → 90% → Good (defaults 80 / 90)
    DailyStatsSummary::factory()->create([
        'business_id' => $admin->business_id,
        'agent_id' => $agent->id,
        'stat_date' => today()->subDay(),
        'orders_count' => 100,
        'confirmed_count' => 80,
        'delivered_count' => 58,
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard', ['agent_id' => $agent->id]))
        ->assertInertia(fn ($page) => $page
            ->where('performance.goals.conf', 80)
            ->where('performance.goals.deliv', 90)
            ->where('performance.view.type', 'agent')
            ->where('performance.view.confPct', 80)
            ->where('performance.view.delivPct', 72.5)
            ->where('performance.view.attainmentPct', 90)
            ->where('performance.view.status', 'Good')
            ->where('performance.ordersLabel', 'orders handled by '.$agent->name)
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
        ->has('upsells', fn ($upsells) => $upsells->has('count')->has('rate'))
        ->has('bestConfirmHour')
        ->where('periodDays', 30)
        ->where('filters', ['period' => null, 'date_from' => null, 'date_to' => null])
        ->where('performance.view.type', 'agent')
        ->where('performance.view.name', $agent->name)
        ->has('performance.daily', 30)
        ->missing('stores')
    );
});

test('the agent dashboard honours the period presets and a custom range', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);
    $this->actingAs($agent);

    $this->get(route('dashboard', ['period' => 'today']))
        ->assertInertia(fn ($page) => $page
            ->where('periodDays', 1)
            ->where('filters.period', 'today')
            ->has('confirmationRateTrend', 1)
            ->etc()
        );

    $this->get(route('dashboard', ['period' => '7d']))
        ->assertInertia(fn ($page) => $page
            ->where('periodDays', 7)
            ->has('deliveryRateTrend', 7)
            ->etc()
        );

    $from = now()->subDays(9)->toDateString();
    $to = now()->subDays(5)->toDateString();

    $this->get(route('dashboard', ['period' => 'custom', 'date_from' => $from, 'date_to' => $to]))
        ->assertInertia(fn ($page) => $page
            ->where('periodDays', 5)
            ->where('filters.period', 'custom')
            ->where('filters.date_from', $from)
            ->where('filters.date_to', $to)
            ->has('confirmationRateTrend', 5)
            ->etc()
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
        ->has('performance.daily', 5)
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
        ->has('performance.daily', 3)
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
        ->has('performance.daily', 366)
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
        ->has('performance.daily', 3)
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
            ->assertInertia(fn ($page) => $page->has('performance.daily', 30)->etc());
    }
});

test('the agent dashboard counts upsells and finds the best confirm hour', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    $upsold = Order::factory()->create(['business_id' => $agent->business_id, 'assigned_agent_id' => $agent->id, 'upsell_amount' => 40]);
    $plain = Order::factory()->create(['business_id' => $agent->business_id, 'assigned_agent_id' => $agent->id, 'upsell_amount' => null]);

    foreach ([[$upsold, 19], [$plain, 19], [$upsold, 9]] as [$order, $hour]) {
        OrderStatusEvent::forceCreate([
            'business_id' => $agent->business_id,
            'order_id' => $order->id,
            'to_status' => OrderConfirmationStatus::CONFIRMED->value,
            'changed_by_user_id' => $agent->id,
            'created_at' => now()->setTime($hour, 15),
        ]);
    }

    $this->actingAs($agent)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('upsells.count', 1)
            ->where('bestConfirmHour', 19));
});

test('the income chart follows the selected period', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $this->actingAs($admin);

    $this->get(route('dashboard', ['period' => '7d']))
        ->assertInertia(fn ($page) => $page->has('income.buckets', 7)->where('income.grain', 'day'));

    $this->get(route('dashboard', ['period' => '90d']))
        ->assertInertia(fn ($page) => $page->has('income.buckets', 13)->where('income.grain', 'week'));

    $this->get(route('dashboard', ['period' => 'custom', 'date_from' => '2026-01-01', 'date_to' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page->has('income.buckets', 6)->where('income.grain', 'month'));
});

test('the settlement alert totals the shortfalls of disputed settlements in the selected range', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $courier = DeliveryCourrier::factory()->create(['name' => 'OzonExpress']);
    $account = DeliveryAccount::factory()->create(['business_id' => $admin->business_id, 'courier_id' => $courier->id]);

    foreach ([[-562.53, '2026-01'], [-102.67, '2026-02'], [61.49, '2026-03']] as [$diff, $month]) {
        CourierSettlement::create([
            'business_id' => $admin->business_id,
            'delivery_account_id' => $account->id,
            'period_start' => $month.'-01',
            'period_end' => $month.'-28',
            'expected_amount' => 1000,
            'actual_amount' => 1000 + $diff,
            'difference_amount' => $diff,
            'status' => 'disputed',
        ]);
    }

    $range = ['period' => 'custom', 'date_from' => '2026-01-01', 'date_to' => '2026-03-31'];

    $this->actingAs($admin)->get(route('dashboard', $range))
        ->assertInertia(fn ($page) => $page
            ->where('alerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['kind'] === 'settlement_difference'
                // Only the shortfalls: −562.53 + −102.67; the +61.49 is left out.
                && $a['strong'] === '−665.20 MAD'
                && $a['text'] === 'total settlement difference'))
            ->etc());

    // Last settlement: March's +61.49 is skipped; February's shortfall shows.
    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('expected.lastSettlement.status', 'difference')
            ->where('expected.lastSettlement.differenceMad', -102.67)
            ->etc());

    // February only: just that period's shortfall.
    $this->actingAs($admin)->get(route('dashboard', ['period' => 'custom', 'date_from' => '2026-02-01', 'date_to' => '2026-02-28']))
        ->assertInertia(fn ($page) => $page
            ->where('alerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['kind'] === 'settlement_difference'
                && $a['strong'] === '−102.67 MAD'))
            ->etc());

    // A range with no disputed settlement: no alert.
    $this->actingAs($admin)->get(route('dashboard', ['period' => 'custom', 'date_from' => '2026-05-01', 'date_to' => '2026-05-31']))
        ->assertInertia(fn ($page) => $page
            ->where('alerts', fn ($alerts) => ! collect($alerts)->contains(fn ($a) => $a['kind'] === 'settlement_difference'))
            ->etc());
});
