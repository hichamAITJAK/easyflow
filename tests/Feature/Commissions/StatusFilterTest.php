<?php

use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeStatusAgent(User $admin): User
{
    return User::factory()->create([
        'business_id' => $admin->business_id,
        'role' => UserRole::CONFIRMATION_AGENT,
    ]);
}

function makeInvoiceWithStatus(User $admin, User $agent, string $status): Invoice
{
    return Invoice::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'invoice_number' => 'INV-'.strtoupper($status).'-'.uniqid(),
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'total_amount' => 25,
        'status' => $status,
    ]);
}

function makeStatusEntry(User $admin, User $agent, ?Invoice $invoice = null): CommissionLedgerEntry
{
    return CommissionLedgerEntry::create([
        'business_id' => $admin->business_id,
        'user_id' => $agent->id,
        'order_id' => makeOrder($admin->business_id)->id,
        'invoice_id' => $invoice?->id,
        'amount' => 25,
        'entry_type' => 'earned',
    ]);
}

test('entries filter by uninvoiced returns only entries with no invoice', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);

    $paidInvoice = makeInvoiceWithStatus($admin, $agent, 'paid');
    makeStatusEntry($admin, $agent, $paidInvoice);
    $uninvoiced = makeStatusEntry($admin, $agent);

    $this->actingAs($admin)
        ->get(route('commission-entries.index', ['status' => 'uninvoiced']))
        ->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.id', $uninvoiced->id)
        );
});

test('entries filter by an invoice status returns only entries on such invoices', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);

    $paid = makeInvoiceWithStatus($admin, $agent, 'paid');
    $draft = makeInvoiceWithStatus($admin, $agent, 'draft');
    $onPaid = makeStatusEntry($admin, $agent, $paid);
    makeStatusEntry($admin, $agent, $draft);
    makeStatusEntry($admin, $agent);

    $this->actingAs($admin)
        ->get(route('commission-entries.index', ['status' => 'paid']))
        ->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.id', $onPaid->id)
        );
});

test('an unknown entry status value is ignored rather than filtering everything out', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);
    makeStatusEntry($admin, $agent);
    makeStatusEntry($admin, $agent);

    $this->actingAs($admin)
        ->get(route('commission-entries.index', ['status' => "' or 1=1--"]))
        ->assertInertia(fn ($page) => $page->has('entries.data', 2));
});

test('the entries status filter scopes the bulk invoiceable count', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);

    $paid = makeInvoiceWithStatus($admin, $agent, 'paid');
    makeStatusEntry($admin, $agent, $paid);
    makeStatusEntry($admin, $agent);
    makeStatusEntry($admin, $agent);

    // Two uninvoiced entries are in scope.
    $this->actingAs($admin)
        ->get(route('commission-entries.index', ['status' => 'uninvoiced']))
        ->assertInertia(fn ($page) => $page->where('invoiceableCount', 2));

    // Filtering to already-paid entries leaves nothing the bulk action can
    // consume, so the button must not offer to invoice anything.
    $this->actingAs($admin)
        ->get(route('commission-entries.index', ['status' => 'paid']))
        ->assertInertia(fn ($page) => $page->where('invoiceableCount', 0));
});

test('invoices filter by status', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);

    $paid = makeInvoiceWithStatus($admin, $agent, 'paid');
    makeInvoiceWithStatus($admin, $agent, 'draft');
    makeInvoiceWithStatus($admin, $agent, 'cancelled');

    $this->actingAs($admin)
        ->get(route('commission-entries.index', ['invoice_status' => 'paid']))
        ->assertInertia(
            fn ($page) => $page
                ->has('invoices.data', 1)
                ->where('invoices.data.0.id', $paid->id)
        );
});

test('the two status filters are independent of each other', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);

    $paid = makeInvoiceWithStatus($admin, $agent, 'paid');
    makeInvoiceWithStatus($admin, $agent, 'draft');
    makeStatusEntry($admin, $agent, $paid);
    makeStatusEntry($admin, $agent);

    // Entries scoped to uninvoiced, invoices scoped to paid — each tab keeps
    // its own scope instead of one key driving both tables.
    $this->actingAs($admin)
        ->get(route('commission-entries.index', [
            'status' => 'uninvoiced',
            'invoice_status' => 'paid',
        ]))
        ->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->has('invoices.data', 1)
                ->where('invoices.data.0.id', $paid->id)
        );
});

test('an unknown invoice status value is ignored', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);
    makeInvoiceWithStatus($admin, $agent, 'paid');
    makeInvoiceWithStatus($admin, $agent, 'draft');

    $this->actingAs($admin)
        ->get(route('commission-entries.index', ['invoice_status' => 'bogus']))
        ->assertInertia(fn ($page) => $page->has('invoices.data', 2));
});

test('an agent filtering by status still only sees their own entries', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);
    $otherAgent = makeStatusAgent($admin);

    makeStatusEntry($admin, $agent);
    makeStatusEntry($admin, $otherAgent);

    $this->actingAs($agent)
        ->get(route('commission-entries.index', ['status' => 'uninvoiced']))
        ->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.user_id', $agent->id)
        );
});

test('invoices filter by agent returns only that agent invoices', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);
    $otherAgent = makeStatusAgent($admin);

    $mine = makeInvoiceWithStatus($admin, $agent, 'issued');
    makeInvoiceWithStatus($admin, $otherAgent, 'issued');

    $this->actingAs($admin)
        ->get(route('commission-entries.index', ['invoice_agent' => $agent->id]))
        ->assertInertia(
            fn ($page) => $page
                ->has('invoices.data', 1)
                ->where('invoices.data.0.id', $mine->id)
        );
});

test('the invoice agent filter combines with the invoice status filter', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);
    $otherAgent = makeStatusAgent($admin);

    $wanted = makeInvoiceWithStatus($admin, $agent, 'paid');
    makeInvoiceWithStatus($admin, $agent, 'draft');
    makeInvoiceWithStatus($admin, $otherAgent, 'paid');

    $this->actingAs($admin)
        ->get(route('commission-entries.index', [
            'invoice_agent' => $agent->id,
            'invoice_status' => 'paid',
        ]))
        ->assertInertia(
            fn ($page) => $page
                ->has('invoices.data', 1)
                ->where('invoices.data.0.id', $wanted->id)
        );
});

test('the invoice list ships the owning agent for the table column', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);
    makeInvoiceWithStatus($admin, $agent, 'issued');

    $this->actingAs($admin)
        ->get(route('commission-entries.index'))
        ->assertInertia(
            fn ($page) => $page
                ->where('invoices.data.0.user.id', $agent->id)
                ->where('invoices.data.0.user.name', $agent->name)
                ->has('invoices.data.0.user.avatar')
        );
});

test('an agent cannot use the invoice agent filter to see another agent invoices', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $agent = makeStatusAgent($admin);
    $otherAgent = makeStatusAgent($admin);

    makeInvoiceWithStatus($admin, $agent, 'issued');
    makeInvoiceWithStatus($admin, $otherAgent, 'issued');

    $this->actingAs($agent)
        ->get(route('commission-entries.index', ['invoice_agent' => $otherAgent->id]))
        ->assertInertia(
            fn ($page) => $page
                ->has('invoices.data', 1)
                ->where('invoices.data.0.user_id', $agent->id)
        );
});
