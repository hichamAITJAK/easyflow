<?php

use App\Enums\UserRole;
use App\Jobs\RebuildBusinessStatsJob;
use App\Models\DailyStatsSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** Orders counted on the business-wide dashboard row for today. */
function dashboardOrdersCount(int $businessId): int
{
    return (int) DailyStatsSummary::withoutGlobalScopes()
        ->where('business_id', $businessId)
        ->whereNull('store_id')->whereNull('agent_id')->whereNull('product_id')->whereNull('delivery_account_id')
        ->sum('orders_count');
}

test('deleting orders takes them out of the dashboard stats', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $orders = collect(range(1, 3))->map(fn () => makeOrder($admin->business_id));

    $this->artisan('stats:rebuild', ['--business' => $admin->business_id]);
    expect(dashboardOrdersCount($admin->business_id))->toBe(3);

    $this->actingAs($admin)->delete(route('orders.destroy', $orders[0]));
    expect(dashboardOrdersCount($admin->business_id))->toBe(2);

    $this->actingAs($admin)->delete(route('orders.bulk-destroy'), ['ids' => [$orders[1]->id, $orders[2]->id]]);
    expect(dashboardOrdersCount($admin->business_id))->toBe(0);
});

test('a bulk delete queues one recount for the business', function () {
    Queue::fake();
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $ids = collect(range(1, 3))->map(fn () => makeOrder($admin->business_id)->id)->all();

    $this->actingAs($admin)->delete(route('orders.bulk-destroy'), ['ids' => $ids]);

    Queue::assertPushed(RebuildBusinessStatsJob::class, 1);
    Queue::assertPushed(fn (RebuildBusinessStatsJob $job) => $job->businessId === $admin->business_id);
});
