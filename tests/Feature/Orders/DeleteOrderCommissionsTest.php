<?php

use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function orderCommission(int $businessId, int $orderId, int $userId, array $overrides = []): CommissionLedgerEntry
{
    return CommissionLedgerEntry::create([
        'business_id' => $businessId,
        'user_id' => $userId,
        'order_id' => $orderId,
        'amount' => 25,
        'entry_type' => 'earned',
        ...$overrides,
    ]);
}

test('deleting an order keeps its commissions unless asked otherwise', function () {
    Queue::fake();
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::CONFIRMATION_AGENT]);
    $order = makeOrder($admin->business_id);
    $entry = orderCommission($admin->business_id, $order->id, $agent->id);

    $this->actingAs($admin)->delete(route('orders.destroy', $order))->assertRedirect();

    expect($order->fresh()->trashed())->toBeTrue();
    expect(CommissionLedgerEntry::withoutGlobalScopes()->find($entry->id))->not->toBeNull();
});

test('deleting an order can take its uninvoiced commissions with it', function () {
    Queue::fake();
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::CONFIRMATION_AGENT]);
    $deliveryGuy = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::DELIVERY_GUY]);
    $order = makeOrder($admin->business_id);

    $invoice = Invoice::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'invoice_number' => 'INV-TEST-1',
        'period_start' => now()->subMonth(),
        'period_end' => now(),
        'total_amount' => 25,
        'status' => 'issued',
    ]);

    $confirmation = orderCommission($admin->business_id, $order->id, $agent->id);
    $delivery = orderCommission($admin->business_id, $order->id, $deliveryGuy->id, ['amount' => 10]);
    $invoiced = orderCommission($admin->business_id, $order->id, $agent->id, ['invoice_id' => $invoice->id]);

    // Another order's commission must be left alone.
    $otherOrder = makeOrder($admin->business_id);
    $other = orderCommission($admin->business_id, $otherOrder->id, $agent->id);

    $this->actingAs($admin)
        ->delete(route('orders.destroy', $order), ['delete_commissions' => true])
        ->assertRedirect();

    $find = fn (CommissionLedgerEntry $entry) => CommissionLedgerEntry::withoutGlobalScopes()->find($entry->id);

    expect($find($confirmation))->toBeNull();
    expect($find($delivery))->toBeNull();
    expect($find($invoiced))->not->toBeNull();
    expect($find($other))->not->toBeNull();
});

test('bulk delete honours the same choice and only for the business\'s own orders', function () {
    Queue::fake();
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::CONFIRMATION_AGENT]);
    $orders = collect(range(1, 2))->map(fn () => makeOrder($admin->business_id));
    $entries = $orders->map(fn ($order) => orderCommission($admin->business_id, $order->id, $agent->id));

    $stranger = makeBusinessUser(['role' => UserRole::ADMIN]);
    $foreignOrder = makeOrder($stranger->business_id);
    $foreignEntry = orderCommission($stranger->business_id, $foreignOrder->id, $stranger->id);

    $this->actingAs($admin)
        ->delete(route('orders.bulk-destroy'), [
            'ids' => [...$orders->pluck('id')->all(), $foreignOrder->id],
            'delete_commissions' => true,
        ])
        ->assertRedirect();

    foreach ($entries as $entry) {
        expect(CommissionLedgerEntry::withoutGlobalScopes()->find($entry->id))->toBeNull();
    }
    expect(CommissionLedgerEntry::withoutGlobalScopes()->find($foreignEntry->id))->not->toBeNull();
    expect($foreignOrder->fresh()->trashed())->toBeFalse();
});
