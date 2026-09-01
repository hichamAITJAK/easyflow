<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Models\CommissionRule;
use App\Models\DailyStatsSummary;
use App\Models\DeliveryAccount;
use App\Models\HourlyConfirmationStat;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Scopes\BusinessScope;
use App\Models\User;
use App\Services\Operations\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function statsRow(int $businessId, array $scope = []): ?DailyStatsSummary
{
    return DailyStatsSummary::withoutGlobalScope(BusinessScope::class)
        ->where('business_id', $businessId)
        ->where('store_id', $scope['store_id'] ?? null)
        ->where('agent_id', $scope['agent_id'] ?? null)
        ->where('product_id', $scope['product_id'] ?? null)
        ->where('delivery_account_id', $scope['delivery_account_id'] ?? null)
        ->first();
}

test('confirming an order records revenue_confirmed and a confirm duration', function () {
    $admin = makeBusinessUser();
    $agent = User::factory()->create(['business_id' => $admin->business_id]);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'new',
        'assigned_agent_id' => $agent->id,
        'total_amount' => 250,
    ]);

    $service = app(OrderService::class);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::ASSIGNED);

    $this->travel(45)->minutes();
    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    $businessRow = statsRow($admin->business_id);
    $agentRow = statsRow($admin->business_id, ['agent_id' => $agent->id]);

    expect((float) $businessRow->revenue_confirmed)->toBe(250.0)
        ->and($businessRow->confirmed_count)->toBe(1)
        ->and($businessRow->confirm_seconds_total)->toBeGreaterThanOrEqual(45 * 60 - 60)
        ->and((float) $agentRow->revenue_confirmed)->toBe(250.0);
});

test('a delivered order records revenue_delivered and a delivery duration', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'submitted_to_courier',
        'total_amount' => 480,
        'shipped_at' => now()->subDays(2),
    ]);

    app(OrderService::class)->updateDeliveryStatus($order, OrderDeliveryStatus::DELIVERED);

    $row = statsRow($admin->business_id);

    expect((float) $row->revenue_delivered)->toBe(480.0)
        ->and($row->delivered_count)->toBe(1)
        ->and($row->delivery_seconds_total)->toBeGreaterThanOrEqual(2 * 86400 - 60);
});

test('stats fan out to courier- and product-scoped rows', function () {
    $admin = makeBusinessUser();
    $account = DeliveryAccount::factory()->create(['business_id' => $admin->business_id]);
    $product = Product::factory()->create(['business_id' => $admin->business_id]);

    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'submitted_to_courier',
        'total_amount' => 100,
        'delivery_account_id' => $account->id,
        'shipped_at' => now()->subDay(),
    ]);

    OrderItem::factory()->create([
        'business_id' => $admin->business_id,
        'order_id' => $order->id,
        'product_id' => $product->id,
    ]);

    app(OrderService::class)->updateDeliveryStatus($order, OrderDeliveryStatus::DELIVERED);

    $courierRow = statsRow($admin->business_id, ['delivery_account_id' => $account->id]);
    $productRow = statsRow($admin->business_id, ['product_id' => $product->id]);

    expect($courierRow->delivered_count)->toBe(1)
        ->and((float) $courierRow->revenue_delivered)->toBe(100.0)
        ->and($courierRow->delivery_seconds_total)->toBeGreaterThan(0)
        ->and($productRow->delivered_count)->toBe(1)
        ->and((float) $productRow->revenue_delivered)->toBe(100.0);
});

test('commission calculation mirrors into commission_total', function () {
    $admin = makeBusinessUser();
    $agent = User::factory()->create(['business_id' => $admin->business_id]);

    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'assigned',
        'assigned_agent_id' => $agent->id,
    ]);

    OrderItem::factory()->create([
        'business_id' => $admin->business_id,
        'order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    CommissionRule::factory()->create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'amount' => 15,
    ]);

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect((float) statsRow($admin->business_id)->commission_total)->toBe(15.0)
        ->and((float) statsRow($admin->business_id, ['agent_id' => $agent->id])->commission_total)->toBe(15.0);
});

test('agent contact outcomes fill hourly confirmation buckets', function () {
    $admin = makeBusinessUser();
    $agent = User::factory()->create(['business_id' => $admin->business_id]);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'assigned',
        'assigned_agent_id' => $agent->id,
    ]);

    $service = app(OrderService::class);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::NO_ANSWER);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    $businessBucket = HourlyConfirmationStat::withoutGlobalScope(BusinessScope::class)
        ->where('business_id', $admin->business_id)
        ->whereNull('agent_id')
        ->where('hour', now()->hour)
        ->first();

    $agentBucket = HourlyConfirmationStat::withoutGlobalScope(BusinessScope::class)
        ->where('business_id', $admin->business_id)
        ->where('agent_id', $agent->id)
        ->where('hour', now()->hour)
        ->first();

    expect($businessBucket->attempts_count)->toBe(2)
        ->and($businessBucket->confirmed_count)->toBe(1)
        ->and($agentBucket->attempts_count)->toBe(2)
        ->and($agentBucket->confirmed_count)->toBe(1);
});

test('test orders never touch stats or hourly buckets', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'assigned',
        'is_test' => true,
        'assigned_agent_id' => User::factory()->create(['business_id' => $admin->business_id])->id,
    ]);

    app(OrderService::class)->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    expect(
        DailyStatsSummary::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $admin->business_id)->count(),
    )->toBe(0)->and(
        HourlyConfirmationStat::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $admin->business_id)->count(),
    )->toBe(0);
});

test('stats:rebuild reproduces what the live listeners wrote', function () {
    $admin = makeBusinessUser();
    $agent = User::factory()->create(['business_id' => $admin->business_id]);
    $order = makeOrder($admin->business_id, [
        'confirmation_status' => 'new',
        'assigned_agent_id' => $agent->id,
        'total_amount' => 300,
    ]);

    $service = app(OrderService::class);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::ASSIGNED);
    $service->updateStatus($order, $admin, OrderConfirmationStatus::CONFIRMED);

    $live = statsRow($admin->business_id);
    $liveConfirmed = $live->confirmed_count;
    $liveRevenue = (float) $live->revenue_confirmed;

    $this->artisan('stats:rebuild', ['--business' => $admin->business_id])->assertSuccessful();

    $rebuilt = statsRow($admin->business_id);

    expect($rebuilt->confirmed_count)->toBe($liveConfirmed)
        ->and((float) $rebuilt->revenue_confirmed)->toBe($liveRevenue)
        ->and($rebuilt->assigned_count)->toBe(1);
});
