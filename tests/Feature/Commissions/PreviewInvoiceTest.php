<?php

use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePreviewInvoice(int $businessId, int $userId): Invoice
{
    $invoice = Invoice::create([
        'business_id' => $businessId,
        'user_id' => $userId,
        'invoice_number' => 'INV-PREVIEW-1',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'total_amount' => 120,
        'status' => 'issued',
    ]);

    $order = makeOrder($businessId, ['reference' => 'ORD-PREVIEW-1']);

    CommissionLedgerEntry::create([
        'business_id' => $businessId,
        'user_id' => $userId,
        'order_id' => $order->id,
        'invoice_id' => $invoice->id,
        'amount' => 120,
        'entry_type' => 'earned',
    ]);

    return $invoice;
}

test('admin previews an invoice with its line items', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $invoice = makePreviewInvoice($admin->business_id, $admin->id);

    $this->actingAs($admin)
        ->getJson(route('commission-entries.invoices.show', $invoice))
        ->assertOk()
        ->assertJsonPath('invoice_number', 'INV-PREVIEW-1')
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('agent.name', $admin->name)
        ->assertJsonPath('entries.0.reference', 'ORD-PREVIEW-1')
        ->assertJsonCount(1, 'entries');
});

test('the preview only returns entries belonging to that invoice', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $invoice = makePreviewInvoice($admin->business_id, $admin->id);

    // An uninvoiced entry in the same period must not leak into the preview:
    // line items come from invoice_id, not from a date range.
    CommissionLedgerEntry::create([
        'business_id' => $admin->business_id,
        'user_id' => $admin->id,
        'order_id' => makeOrder($admin->business_id)->id,
        'invoice_id' => null,
        'amount' => 999,
        'entry_type' => 'earned',
    ]);

    $this->actingAs($admin)
        ->getJson(route('commission-entries.invoices.show', $invoice))
        ->assertOk()
        ->assertJsonCount(1, 'entries')
        ->assertJsonPath('entries.0.amount', '120.00');
});

test('a confirmation agent cannot preview an invoice', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $invoice = makePreviewInvoice($admin->business_id, $admin->id);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($agent)
        ->getJson(route('commission-entries.invoices.show', $invoice))
        ->assertForbidden();
});

test('an invoice from another business is not previewable', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $otherAdmin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $foreign = makePreviewInvoice($otherAdmin->business_id, $otherAdmin->id);

    // 404, not 403: Invoice is #[ScopedBy(BusinessScope)], so route-model
    // binding never resolves another tenant's invoice.
    $this->actingAs($admin)
        ->getJson(route('commission-entries.invoices.show', $foreign))
        ->assertNotFound();
});

test('guests cannot preview an invoice', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $invoice = makePreviewInvoice($admin->business_id, $admin->id);

    $this->getJson(route('commission-entries.invoices.show', $invoice))
        ->assertUnauthorized();
});

test('an agent or editor previews their own invoice', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $editor = makeBusinessUser(['role' => UserRole::CREATIVES_EDITOR, 'business_id' => $admin->business_id]);
    $invoice = makePreviewInvoice($admin->business_id, $editor->id);

    $this->actingAs($editor)
        ->getJson(route('commission-entries.invoices.show', $invoice))
        ->assertOk()
        ->assertJsonPath('invoice_number', $invoice->invoice_number);
});
