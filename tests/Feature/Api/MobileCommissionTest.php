<?php

use App\Models\CommissionLedgerEntry;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function actingAsConfirmationAgentForCommission(): array
{
    $user = makeBusinessUser(['role' => 'confirmation_agent']);
    $token = $user->createToken('device')->plainTextToken;

    return [$user, $token];
}

test('the commission index only returns the authenticated agent\'s own entries', function () {
    [$user, $token] = actingAsConfirmationAgentForCommission();
    $otherAgent = makeBusinessUser(['role' => 'confirmation_agent', 'business_id' => $user->business_id]);

    $own = CommissionLedgerEntry::factory()->create(['business_id' => $user->business_id, 'user_id' => $user->id]);
    CommissionLedgerEntry::factory()->create(['business_id' => $user->business_id, 'user_id' => $otherAgent->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.commission.index'));

    $response->assertOk();
    $ids = collect($response->json('entries'))->pluck('id');

    expect($ids)->toHaveCount(1);
    expect($ids)->toContain($own->id);
});

test('the commission index only returns entries for the authenticated user\'s business', function () {
    [$user, $token] = actingAsConfirmationAgentForCommission();
    $otherUser = makeBusinessUser(['role' => 'confirmation_agent']);

    CommissionLedgerEntry::factory()->create(['business_id' => $otherUser->business_id, 'user_id' => $otherUser->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.commission.index'));

    $response->assertOk();
    expect($response->json('entries'))->toBeEmpty();
});

test('an entry with no invoice yet reports settlement_status pending', function () {
    [$user, $token] = actingAsConfirmationAgentForCommission();
    CommissionLedgerEntry::factory()->create(['business_id' => $user->business_id, 'user_id' => $user->id, 'invoice_id' => null]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.commission.index'));

    $response->assertOk();
    expect($response->json('entries.0.settlement_status'))->toBe('pending');
});

test('an entry with a paid invoice reports settlement_status paid', function () {
    [$user, $token] = actingAsConfirmationAgentForCommission();
    $invoice = Invoice::factory()->paid()->create(['business_id' => $user->business_id, 'user_id' => $user->id]);
    CommissionLedgerEntry::factory()->create([
        'business_id' => $user->business_id,
        'user_id' => $user->id,
        'invoice_id' => $invoice->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.commission.index'));

    $response->assertOk();
    expect($response->json('entries.0.settlement_status'))->toBe('paid');
});

test('the commission index includes the order reference', function () {
    [$user, $token] = actingAsConfirmationAgentForCommission();
    $order = makeOrder($user->business_id, ['reference' => 'REF-123']);
    CommissionLedgerEntry::factory()->create(['business_id' => $user->business_id, 'user_id' => $user->id, 'order_id' => $order->id]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.commission.index'));

    $response->assertOk();
    expect($response->json('entries.0.order_reference'))->toBe('REF-123');
});

test('the commission index filters by date range', function () {
    [$user, $token] = actingAsConfirmationAgentForCommission();

    $inRange = CommissionLedgerEntry::factory()->create(['business_id' => $user->business_id, 'user_id' => $user->id]);
    $inRange->timestamps = false;
    $inRange->created_at = now()->subDays(2);
    $inRange->save();

    $outOfRange = CommissionLedgerEntry::factory()->create(['business_id' => $user->business_id, 'user_id' => $user->id]);
    $outOfRange->created_at = now()->subDays(30);
    $outOfRange->save();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.commission.index', [
            'date_from' => now()->subDays(5)->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

    $response->assertOk();
    $ids = collect($response->json('entries'))->pluck('id');

    expect($ids)->toHaveCount(1);
    expect($ids)->toContain($inRange->id);
});

test('the commission summary totals earned minus reversed amounts, scoped to the agent', function () {
    [$user, $token] = actingAsConfirmationAgentForCommission();
    $otherAgent = makeBusinessUser(['role' => 'confirmation_agent', 'business_id' => $user->business_id]);

    $earned = CommissionLedgerEntry::factory()->create(['business_id' => $user->business_id, 'user_id' => $user->id, 'amount' => 50]);
    CommissionLedgerEntry::factory()->reversal($earned)->create();
    CommissionLedgerEntry::factory()->create(['business_id' => $user->business_id, 'user_id' => $user->id, 'amount' => 30]);
    CommissionLedgerEntry::factory()->create(['business_id' => $user->business_id, 'user_id' => $otherAgent->id, 'amount' => 999]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.mobile.commission.summary'));

    $response->assertOk();
    $response->assertJson(['total' => 30.0]);
});
