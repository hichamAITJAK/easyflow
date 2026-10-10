<?php

use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function commissionsPageEntry(User $admin, User $agent, float $amount, string $createdAt): CommissionLedgerEntry
{
    $entry = CommissionLedgerEntry::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'order_id' => makeOrder($admin->business_id)->id,
        'amount' => $amount,
        'entry_type' => 'earned',
    ]);
    $entry->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $entry;
}

test('entries sort by amount and by earned date', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::CONFIRMATION_AGENT]);

    $small = commissionsPageEntry($admin, $agent, 10, '2026-10-01 10:00:00');
    $big = commissionsPageEntry($admin, $agent, 90, '2026-09-01 10:00:00');

    $this->actingAs($admin)
        ->get(route('commission-entries.index', ['sort' => 'amount', 'direction' => 'desc']))
        ->assertInertia(fn ($page) => $page->where('entries.data.0.id', $big->id)->where('filters.sort', 'amount')->etc());

    $this->actingAs($admin)
        ->get(route('commission-entries.index'))
        ->assertInertia(fn ($page) => $page->where('entries.data.0.id', $small->id)->etc());
});

test('an agent sees how many of their entries await an invoice', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::CONFIRMATION_AGENT]);
    $other = User::factory()->create(['business_id' => $admin->business_id, 'role' => UserRole::CONFIRMATION_AGENT]);

    commissionsPageEntry($admin, $agent, 10, '2026-10-01 10:00:00');
    commissionsPageEntry($admin, $agent, 20, '2026-10-02 10:00:00');
    commissionsPageEntry($admin, $other, 30, '2026-10-02 10:00:00');

    $this->actingAs($agent)
        ->get(route('commission-entries.index'))
        ->assertInertia(fn ($page) => $page->where('invoiceableCount', 2)->etc());
});
