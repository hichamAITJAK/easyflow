<?php

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The business identity fields exist to appear on invoices — a settings
 * form that fed nothing would be a form that lies. These render the Blade
 * directly rather than the PDF binary, which is what actually asserts the
 * values reach the page.
 */
function renderInvoice(Invoice $invoice): string
{
    return view('pdf.invoice', ['invoice' => $invoice])->render();
}

test('an invoice prints the business identity details', function () {
    $admin = makeBusinessUser();

    $admin->business->update([
        'legal_name' => 'EasyFlow SARL',
        'ice' => '001234567000089',
        'rc' => 'RC-12345',
        'if_number' => 'IF-98765',
        'phone' => '0612345678',
        'email' => 'contact@easyflow.ma',
        'address' => '12 Rue Hassan II',
        'city' => 'Casablanca',
    ]);

    $invoice = Invoice::create([
        'business_id' => $admin->business_id,
        'user_id' => $admin->id,
        'invoice_number' => 'INV-0001',
        'period_start' => now()->subMonth(),
        'period_end' => now(),
        'total_amount' => 1200,
        'status' => 'unpaid',
    ]);

    $html = renderInvoice($invoice->fresh());

    // The registered name wins over the trading name on an invoice.
    expect($html)->toContain('EasyFlow SARL');
    expect($html)->toContain('ICE: 001234567000089');
    expect($html)->toContain('RC: RC-12345');
    expect($html)->toContain('IF: IF-98765');
    expect($html)->toContain('12 Rue Hassan II, Casablanca');
    expect($html)->toContain('0612345678');
});

test('an invoice falls back to the trading name when no details are set', function () {
    $admin = makeBusinessUser();

    $invoice = Invoice::create([
        'business_id' => $admin->business_id,
        'user_id' => $admin->id,
        'invoice_number' => 'INV-0002',
        'period_start' => now()->subMonth(),
        'period_end' => now(),
        'total_amount' => 800,
        'status' => 'unpaid',
    ]);

    $html = renderInvoice($invoice->fresh());

    // Unset identifiers are omitted entirely rather than printed empty.
    expect($html)->toContain($admin->business->name);
    expect($html)->not->toContain('ICE:');
    expect($html)->not->toContain('RC:');
});
