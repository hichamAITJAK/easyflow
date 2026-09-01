<?php

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerBlacklistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an admin blacklists the client behind an order', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['customer_phone' => '+212600000000']);

    $this->actingAs($admin)
        ->post(route('orders.blacklist', $order), ['reason' => 'Chargeback history'])
        ->assertRedirect(route('orders.index'));

    $entry = CustomerBlacklistEntry::where('business_id', $admin->business_id)->firstOrFail();
    expect($entry->phone_hash)->toBe($order->customer_phone_hash);
    expect($entry->reason)->toBe('Chargeback history');
    expect($entry->added_by_user_id)->toBe($admin->id);

    $order->refresh();
    expect($order->is_blacklist_flagged)->toBeTrue();
});

test('blacklisting flags every order from the same phone, not just the one acted on', function () {
    $admin = makeBusinessUser();
    $phone = '+212600000000';
    $order1 = makeOrder($admin->business_id, ['customer_phone' => $phone]);
    $order2 = makeOrder($admin->business_id, ['customer_phone' => $phone]);

    $this->actingAs($admin)->post(route('orders.blacklist', $order1));

    expect($order1->refresh()->is_blacklist_flagged)->toBeTrue();
    expect($order2->refresh()->is_blacklist_flagged)->toBeTrue();
});

test('blacklisting syncs the Customer record and clears best-customer status', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['customer_phone' => '+212600000000']);

    Customer::create([
        'business_id' => $admin->business_id,
        'name' => 'Jane Doe',
        'phone' => $order->customer_phone,
        'phone_hash' => $order->customer_phone_hash,
        'orders_count' => 1,
        'delivered_orders_count' => 0,
        'returned_orders_count' => 0,
        'is_best_customer' => true,
        'is_blacklisted' => false,
    ]);

    $this->actingAs($admin)->post(route('orders.blacklist', $order));

    $customer = Customer::where('business_id', $admin->business_id)->firstOrFail();
    expect($customer->is_blacklisted)->toBeTrue();
    expect($customer->is_best_customer)->toBeFalse();
});

test('a confirmation agent can blacklist a client from their queue', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $order = makeOrder($admin->business_id, ['assigned_agent_id' => $agent->id]);

    $this->actingAs($agent)
        ->post(route('orders.blacklist', $order), ['reason' => 'Prank caller'])
        ->assertRedirect();

    expect(CustomerBlacklistEntry::where('business_id', $admin->business_id)->exists())->toBeTrue();
});

test('blacklisting does not grant a confirmation agent the other admin order actions', function () {
    $admin = makeBusinessUser();
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $order = makeOrder($admin->business_id, ['assigned_agent_id' => $agent->id]);

    // `blacklist-customers` is deliberately its own gate: opening blacklisting
    // to agents must not drag delete/assign/bulk along with it.
    $this->actingAs($agent)
        ->delete(route('orders.destroy', $order))
        ->assertForbidden();

    $this->actingAs($agent)
        ->patch(route('orders.assign', $order), ['assigned_agent_id' => $agent->id])
        ->assertForbidden();
});

test('a test order cannot be used to blacklist a phone number', function () {
    $admin = makeBusinessUser();
    $order = makeOrder($admin->business_id, ['is_test' => true]);

    $this->actingAs($admin)
        ->post(route('orders.blacklist', $order))
        ->assertStatus(422);

    expect(CustomerBlacklistEntry::where('business_id', $admin->business_id)->exists())->toBeFalse();
});

test('an admin cannot blacklist a client on another business\'s order', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();
    $order = makeOrder($otherAdmin->business_id);

    $this->actingAs($admin)
        ->post(route('orders.blacklist', $order))
        ->assertNotFound();
});
