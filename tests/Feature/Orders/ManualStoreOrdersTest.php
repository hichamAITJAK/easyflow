<?php

use App\Enums\StoreConnectionStatus;
use App\Enums\UserRole;
use App\Models\AgentScope;
use App\Models\EcommercePlatform;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$payload = [
    'customer_name' => 'Jane Doe',
    'customer_phone' => '+212600000000',
    'customer_address' => '123 Main St',
    'total_amount' => 100,
    'source_platform' => 'instagram',
];

test('an admin creates a manual store for a brand with no online shop', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)->post(route('stores.manual.store'), ['name' => 'Faizat'])
        ->assertRedirect(route('stores.index'));
    // A second one with the same name gets its own slug.
    $this->actingAs($admin)->post(route('stores.manual.store'), ['name' => 'Faizat'])->assertRedirect();

    $stores = Store::where('business_id', $admin->business_id)->orderBy('id')->get();

    expect($stores)->toHaveCount(2)
        ->and($stores[0]->connection_status)->toBe(StoreConnectionStatus::MANUAL)
        ->and($stores[0]->platform->slug)->toBe('manual')
        ->and($stores->pluck('slug')->all())->toBe(['faizat', 'faizat-2'])
        ->and(EcommercePlatform::where('slug', 'manual')->count())->toBe(1);

    // Never offered as something to connect.
    $this->actingAs($admin)->get(route('stores.create'))
        ->assertInertia(fn ($page) => $page->where('platforms', fn ($platforms) => ! collect($platforms)->contains('slug', 'manual'))->etc());
});

test('a manual order must name its store, and records the channel it came from', function () use ($payload) {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $faizat = makeStore($admin->business_id, ['name' => 'Faizat']);

    $this->actingAs($admin)->post(route('orders.store'), $payload)
        ->assertSessionHasErrors('store_id');

    $this->actingAs($admin)->post(route('orders.store'), [...$payload, 'store_id' => $faizat->id])
        ->assertSessionHasNoErrors();

    $order = Order::where('business_id', $admin->business_id)->firstOrFail();
    expect($order->store_id)->toBe($faizat->id)
        ->and($order->source_platform)->toBe('instagram');

    // Another business's store is not an option.
    $other = makeStore(makeBusinessUser()->business_id);
    $this->actingAs($admin)->post(route('orders.store'), [...$payload, 'store_id' => $other->id])
        ->assertSessionHasErrors('store_id');
});

test('a confirmation agent can only file an order under a store in their scope', function () use ($payload) {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $faizat = makeStore($admin->business_id, ['name' => 'Faizat']);
    $handk = makeStore($admin->business_id, ['name' => 'HandK']);
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    AgentScope::create(['business_id' => $admin->business_id, 'user_id' => $agent->id, 'store_id' => $faizat->id, 'product_id' => null]);

    $this->actingAs($agent)->post(route('orders.store'), [...$payload, 'store_id' => $handk->id])
        ->assertSessionHasErrors('store_id');

    $this->actingAs($agent)->post(route('orders.store'), [...$payload, 'store_id' => $faizat->id])
        ->assertSessionHasNoErrors();

    // The form only offers the agent's own stores.
    $this->actingAs($agent)->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('stores', 1)->where('stores.0.name', 'Faizat')->etc());

    // An agent with no store rows works the whole business.
    $free = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT, 'business_id' => $admin->business_id]);
    $this->actingAs($free)->post(route('orders.store'), [...$payload, 'store_id' => $handk->id])
        ->assertSessionHasNoErrors();
});

test('an order from before stores were required keeps no store until one is picked', function () use ($payload) {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $order = makeOrder($admin->business_id, ['source_platform' => 'whatsapp']);
    $faizat = makeStore($admin->business_id, ['name' => 'Faizat']);

    $this->actingAs($admin)->patch(route('orders.update', $order), $payload)->assertSessionHasNoErrors();
    expect($order->fresh()->store_id)->toBeNull();

    $this->actingAs($admin)->patch(route('orders.update', $order), [...$payload, 'store_id' => $faizat->id])->assertSessionHasNoErrors();
    expect($order->fresh()->store_id)->toBe($faizat->id);
});
