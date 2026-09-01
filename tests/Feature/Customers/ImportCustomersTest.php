<?php

use App\Enums\UserRole;
use App\Models\Customer;
use App\Services\Operations\Customers\CustomerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Build an in-memory CSV upload from raw file contents. */
function csvUpload(string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'customers.csv', 'text/csv', null, true);
}

test('an admin imports a customer list', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => csvUpload(<<<'CSV'
        name,phone,address,city
        Amina,0612345678,12 Rue Hassan,Casablanca
        Youssef,0698765432,4 Avenue Mohammed V,Rabat
        CSV),
    ])->assertRedirect();

    $customers = Customer::where('business_id', $admin->business_id)->get();

    expect($customers)->toHaveCount(2);

    $amina = $customers->firstWhere('phone_hash', hash('sha256', '0612345678'));
    expect($amina->name)->toBe('Amina');
    expect($amina->phone)->toBe('0612345678');
    expect($amina->city)->toBe('Casablanca');
    // An imported contact has no order history in this business yet.
    expect($amina->orders_count)->toBe(0);
    expect($amina->last_order_at)->toBeNull();
});

test('import stores name, phone and address encrypted at rest', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => csvUpload("name,phone,address,city\nAmina,0612345678,12 Rue Hassan,Casablanca\n"),
    ])->assertRedirect();

    // Read straight past the model casts: PII must never sit in plaintext.
    $raw = DB::table('customers')->where('business_id', $admin->business_id)->first();

    expect($raw->name)->not->toBe('Amina');
    expect($raw->phone)->not->toBe('0612345678');
    expect($raw->address)->not->toBe('12 Rue Hassan');
    // The hash is the lookup key and is deliberately not encrypted.
    expect($raw->phone_hash)->toBe(hash('sha256', '0612345678'));
    // City is not PII under the PRD and stays searchable in plaintext.
    expect($raw->city)->toBe('Casablanca');
});

test('a phone matching an existing customer updates instead of duplicating', function () {
    $admin = makeBusinessUser();

    $existing = Customer::create([
        'business_id' => $admin->business_id,
        'name' => 'Old Name',
        'phone' => '0612345678',
        'phone_hash' => hash('sha256', '0612345678'),
        'address' => 'Existing address',
        'city' => 'Fes',
        'orders_count' => 7,
        'delivered_orders_count' => 5,
        'returned_orders_count' => 1,
        'last_order_at' => now()->subDay(),
        'is_best_customer' => true,
        'is_blacklisted' => false,
    ]);

    // Sparse row: no address column at all.
    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => csvUpload("name,phone,city\nNew Name,0612345678,Casablanca\n"),
    ])->assertRedirect();

    expect(Customer::where('business_id', $admin->business_id)->count())->toBe(1);

    $existing->refresh();
    expect($existing->name)->toBe('New Name');
    expect($existing->city)->toBe('Casablanca');
    // A column the file didn't carry must not be blanked.
    expect($existing->address)->toBe('Existing address');
    // Order-derived facts are never asserted by a spreadsheet.
    expect($existing->orders_count)->toBe(7);
    expect($existing->delivered_orders_count)->toBe(5);
    expect($existing->is_best_customer)->toBeTrue();
});

test('differently formatted phone numbers resolve to one customer', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => csvUpload(<<<'CSV'
        name,phone
        Amina,+212 612-345-678
        Amina Again,0612345678
        CSV),
    ])->assertRedirect();

    // Both normalise to 0612345678, so the second row is a within-file
    // duplicate rather than a second customer or a unique-index violation.
    expect(Customer::where('business_id', $admin->business_id)->count())->toBe(1);
});

test('rows with an unusable phone are skipped and reported by row number', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => csvUpload(<<<'CSV'
        name,phone,city
        Valid,0612345678,Casablanca
        No Phone,,Rabat
        Too Short,123,Fes
        CSV),
    ])->assertRedirect();

    expect(Customer::where('business_id', $admin->business_id)->count())->toBe(1);

    $result = session('importResult');
    expect($result['imported'])->toBe(1);
    expect($result['skipped'])->toBe(2);
    expect($result['errors'])->toHaveCount(2);
    // Reported by row number, never by echoing the offending value (PRD:
    // client PII must never appear in logs or error messages).
    expect($result['errors'][0]['row'])->toBe(3);
    expect($result['errors'][1]['row'])->toBe(4);

    foreach ($result['errors'] as $error) {
        expect($error['reason'])->not->toContain('123');
    }
});

test('unknown columns are ignored and a missing phone column is rejected', function () {
    $admin = makeBusinessUser();

    // Extra columns from a merchant's own export shouldn't block the import.
    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => csvUpload("name,phone,loyalty_tier\nAmina,0612345678,gold\n"),
    ])->assertRedirect();

    expect(Customer::where('business_id', $admin->business_id)->count())->toBe(1);

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => csvUpload("name,city\nAmina,Casablanca\n"),
    ])->assertRedirect();

    expect(Customer::where('business_id', $admin->business_id)->count())->toBe(1);
    expect(session('importResult')['errors'][0]['reason'])->toContain('phone');
});

test('an import cannot write into another business', function () {
    $admin = makeBusinessUser();
    $otherAdmin = makeBusinessUser();

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => csvUpload("name,phone\nAmina,0612345678\n"),
    ])->assertRedirect();

    // business_id comes from the authenticated user, never from the file.
    expect(Customer::withoutGlobalScopes()->where('business_id', $otherAdmin->business_id)->count())->toBe(0);
    expect(Customer::withoutGlobalScopes()->where('business_id', $admin->business_id)->count())->toBe(1);
});

test('a confirmation agent cannot import customers', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    $this->actingAs($agent)->post(route('customers.import'), [
        'file' => csvUpload("name,phone\nAmina,0612345678\n"),
    ])->assertForbidden();

    expect(Customer::withoutGlobalScopes()->count())->toBe(0);
});

test('a non-csv upload is rejected', function () {
    $admin = makeBusinessUser();

    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => UploadedFile::fake()->create('customers.pdf', 10, 'application/pdf'),
    ])->assertSessionHasErrors('file');

    expect(Customer::withoutGlobalScopes()->count())->toBe(0);
});

test('the template download carries the headers the parser accepts', function () {
    $admin = makeBusinessUser();

    $response = $this->actingAs($admin)->get(route('customers.import.template'));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $body = $response->streamedContent();
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($body))));

    // Leading BOM so Excel reads it as UTF-8; strip it before comparing.
    $header = array_map(fn (string $cell) => trim($cell, "\u{FEFF}"), $rows[0]);

    expect($header)->toBe(CustomerImportService::HEADERS);
    expect($rows)->toHaveCount(2);
});

test('the downloaded template imports cleanly without editing', function () {
    $admin = makeBusinessUser();

    $body = $this->actingAs($admin)
        ->get(route('customers.import.template'))
        ->streamedContent();

    // Round-trip: whatever the template hands out must satisfy the parser,
    // or the starter file teaches the wrong format.
    $this->actingAs($admin)->post(route('customers.import'), [
        'file' => csvUpload($body),
    ])->assertRedirect();

    $result = session('importResult');
    expect($result['imported'])->toBe(1);
    expect($result['skipped'])->toBe(0);
    expect($result['errors'])->toBe([]);
});

test('an agent cannot download the import template', function () {
    $agent = makeBusinessUser(['role' => UserRole::CONFIRMATION_AGENT]);

    $this->actingAs($agent)
        ->get(route('customers.import.template'))
        ->assertForbidden();
});
