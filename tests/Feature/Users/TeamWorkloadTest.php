<?php

use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the team page shows each confirmation agent their open orders', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::CONFIRMATION_AGENT]);
    $idle = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::CONFIRMATION_AGENT]);

    $mine = ['assigned_agent_id' => $agent->id];
    makeOrder($admin->business_id, [...$mine, 'confirmation_status' => OrderConfirmationStatus::ASSIGNED]);
    makeOrder($admin->business_id, [...$mine, 'confirmation_status' => OrderConfirmationStatus::NEW]);
    makeOrder($admin->business_id, [...$mine, 'confirmation_status' => OrderConfirmationStatus::CALLBACK]);
    makeOrder($admin->business_id, [...$mine, 'confirmation_status' => OrderConfirmationStatus::CONFIRMED]);
    makeOrder($admin->business_id, [...$mine, 'confirmation_status' => OrderConfirmationStatus::ASSIGNED, 'is_test' => true]);

    $this->actingAs($admin)->get(route('users.index'))
        ->assertInertia(fn ($page) => $page
            ->where("workload.{$agent->id}", ['open' => 3, 'toCall' => 2, 'followUp' => 1])
            ->where("workload.{$idle->id}", ['open' => 0, 'toCall' => 0, 'followUp' => 0])
            ->etc());
});

test('the team page counts parcels in each fulfilment agent stores only', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $storeA = Store::factory()->create(['business_id' => $admin->business_id]);
    $storeB = Store::factory()->create(['business_id' => $admin->business_id]);

    $scoped = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::FULFILMENT_AGENT]);
    AgentScope::create(['business_id' => $admin->business_id, 'user_id' => $scoped->id, 'store_id' => $storeA->id]);
    $everywhere = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::FULFILMENT_AGENT]);

    makeOrder($admin->business_id, ['store_id' => $storeA->id, 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP]);
    makeOrder($admin->business_id, ['store_id' => $storeA->id, 'delivery_status' => OrderDeliveryStatus::RETURNED_IN_TRANSIT]);
    makeOrder($admin->business_id, ['store_id' => $storeB->id, 'delivery_status' => OrderDeliveryStatus::AWAITING_PICKUP]);
    makeOrder($admin->business_id, ['store_id' => $storeB->id, 'delivery_status' => OrderDeliveryStatus::DELIVERED]);

    $this->actingAs($admin)->get(route('users.index'))
        ->assertInertia(fn ($page) => $page
            ->where("workload.{$scoped->id}", ['toPrepare' => 1, 'returns' => 1])
            ->where("workload.{$everywhere->id}", ['toPrepare' => 2, 'returns' => 1])
            ->etc());
});
