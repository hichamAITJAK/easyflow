<?php

use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeEntry(int $businessId, int $userId, array $overrides = []): CommissionLedgerEntry
{
    $order = makeOrder($businessId);

    return CommissionLedgerEntry::create([
        'business_id' => $businessId,
        'user_id' => $userId,
        'order_id' => $order->id,
        'amount' => 25,
        'entry_type' => 'earned',
        ...$overrides,
    ]);
}

function makeAgentFor(User $admin, string $name = 'Agent'): User
{
    return User::factory()->create([
        'business_id' => $admin->business_id,
        'role' => UserRole::CONFIRMATION_AGENT,
        'name' => $name,
    ]);
}

test('bulk generate invoices every uninvoiced entry matching no filters', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeAgentFor($admin);

    makeEntry($admin->business_id, $agent->id);
    makeEntry($admin->business_id, $agent->id);
    makeEntry($admin->business_id, $agent->id);

    $this->actingAs($admin)
        ->post(route('commission-entries.invoices.from-filters'))
        ->assertRedirect();

    expect(Invoice::count())->toBe(1);
    expect(CommissionLedgerEntry::whereNull('invoice_id')->count())->toBe(0);
});

test('bulk generate respects the agent filter', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $target = makeAgentFor($admin, 'Target');
    $other = makeAgentFor($admin, 'Other');

    makeEntry($admin->business_id, $target->id);
    makeEntry($admin->business_id, $target->id);
    $untouched = makeEntry($admin->business_id, $other->id);

    $this->actingAs($admin)
        ->post(route('commission-entries.invoices.from-filters', ['agent_id' => $target->id]))
        ->assertRedirect();

    expect(Invoice::count())->toBe(1);
    expect(Invoice::first()->user_id)->toBe($target->id);
    // The other agent's entry must be left alone entirely.
    expect($untouched->fresh()->invoice_id)->toBeNull();
});

test('bulk generate respects the max amount filter', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeAgentFor($admin);

    $small = makeEntry($admin->business_id, $agent->id, ['amount' => 10]);
    $large = makeEntry($admin->business_id, $agent->id, ['amount' => 500]);

    $this->actingAs($admin)
        ->post(route('commission-entries.invoices.from-filters', ['max_amount' => 100]))
        ->assertRedirect();

    expect($small->fresh()->invoice_id)->not->toBeNull();
    expect($large->fresh()->invoice_id)->toBeNull();
});

test('bulk generate respects the date range filter', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeAgentFor($admin);

    // created_at is not fillable on this model ($timestamps = false), so it
    // has to be forced in rather than passed to create().
    $inRange = makeEntry($admin->business_id, $agent->id);
    $inRange->forceFill(['created_at' => '2026-06-15 10:00:00'])->save();

    $outOfRange = makeEntry($admin->business_id, $agent->id);
    $outOfRange->forceFill(['created_at' => '2026-08-15 10:00:00'])->save();

    $this->actingAs($admin)
        ->post(route('commission-entries.invoices.from-filters', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertRedirect();

    expect($inRange->fresh()->invoice_id)->not->toBeNull();
    expect($outOfRange->fresh()->invoice_id)->toBeNull();
});

test('bulk generate creates one invoice per agent', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $first = makeAgentFor($admin, 'First');
    $second = makeAgentFor($admin, 'Second');

    makeEntry($admin->business_id, $first->id);
    makeEntry($admin->business_id, $first->id);
    makeEntry($admin->business_id, $second->id);

    $this->actingAs($admin)
        ->post(route('commission-entries.invoices.from-filters'))
        ->assertRedirect();

    expect(Invoice::count())->toBe(2);
    expect(Invoice::pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

test('bulk generate never touches already invoiced entries', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeAgentFor($admin);

    $existing = Invoice::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'invoice_number' => 'INV-EXISTING',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'total_amount' => 25,
        'status' => 'paid',
    ]);
    $alreadyInvoiced = makeEntry($admin->business_id, $agent->id, [
        'invoice_id' => $existing->id,
    ]);
    makeEntry($admin->business_id, $agent->id);

    $this->actingAs($admin)
        ->post(route('commission-entries.invoices.from-filters'))
        ->assertRedirect();

    // Still on its original invoice, not re-bundled into the new one.
    expect($alreadyInvoiced->fresh()->invoice_id)->toBe($existing->id);
    expect(Invoice::count())->toBe(2);
});

test('bulk generate never crosses a business boundary', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeAgentFor($admin);
    makeEntry($admin->business_id, $agent->id);

    $otherAdmin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $otherAgent = makeAgentFor($otherAdmin, 'Foreign');
    $foreignEntry = makeEntry($otherAdmin->business_id, $otherAgent->id);

    $this->actingAs($admin)
        ->post(route('commission-entries.invoices.from-filters'))
        ->assertRedirect();

    expect($foreignEntry->fresh()->invoice_id)->toBeNull();
    expect(Invoice::where('business_id', $otherAdmin->business_id)->count())->toBe(0);
});

test('bulk generate reports when nothing matches instead of erroring', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)
        ->post(route('commission-entries.invoices.from-filters'))
        ->assertRedirect();

    expect(Invoice::count())->toBe(0);
});

test('a confirmation agent cannot bulk generate invoices', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeAgentFor($admin);
    makeEntry($admin->business_id, $agent->id);

    $this->actingAs($agent)
        ->post(route('commission-entries.invoices.from-filters'))
        ->assertForbidden();

    expect(Invoice::count())->toBe(0);
});
