<?php

use App\Enums\UserRole;
use App\Models\CommissionLedgerEntry;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeInvoiceWithEntries(int $businessId, int $userId): Invoice
{
    $invoice = Invoice::create([
        'business_id' => $businessId,
        'user_id' => $userId,
        'invoice_number' => 'INV-TEST-0001',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'total_amount' => 75,
        'status' => 'pending',
    ]);

    $order = makeOrder($businessId, ['reference' => 'ORD-TEST-1']);

    CommissionLedgerEntry::create([
        'business_id' => $businessId,
        'user_id' => $userId,
        'order_id' => $order->id,
        'invoice_id' => $invoice->id,
        'amount' => 75,
        'entry_type' => 'earned',
    ]);

    return $invoice;
}

test('admin downloads an invoice as a pdf', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $invoice = makeInvoiceWithEntries($admin->business_id, $admin->id);

    $response = $this->actingAs($admin)->get(
        route('commission-entries.invoices.download', $invoice)
    );

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('content-disposition'))
        ->toContain('INV-TEST-0001.pdf');
    // Guards against a 0-byte or HTML-error body being served as a PDF.
    expect($response->getContent())->toStartWith('%PDF-');
});

test('an invoice from another business is not downloadable', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $otherAdmin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $foreign = makeInvoiceWithEntries($otherAdmin->business_id, $otherAdmin->id);

    // 404, not 403: Invoice is #[ScopedBy(BusinessScope)], so route-model
    // binding never resolves another tenant's invoice in the first place.
    // The controller's business_id check is the second line of defence.
    $this->actingAs($admin)
        ->get(route('commission-entries.invoices.download', $foreign))
        ->assertNotFound();
});

test('a confirmation agent cannot download an invoice', function () {
    $admin = makeBusinessUser(['role' => UserRole::ADMIN]);
    $invoice = makeInvoiceWithEntries($admin->business_id, $admin->id);

    $agent = makeBusinessUser([
        'role' => UserRole::CONFIRMATION_AGENT,
        'business_id' => $admin->business_id,
    ]);

    $this->actingAs($agent)
        ->get(route('commission-entries.invoices.download', $invoice))
        ->assertForbidden();
});
