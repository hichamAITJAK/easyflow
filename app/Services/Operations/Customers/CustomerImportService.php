<?php

namespace App\Services\Operations\Customers;

use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Imports a business's own customer list from a CSV upload.
 *
 * Rows are matched to existing customers on (business_id, phone_hash) —
 * the same key UpsertCustomerOnOrderCreated uses — so importing a list that
 * overlaps the customers already built from orders updates those rows
 * instead of colliding with the table's unique index. Order-derived
 * counters (orders_count, delivered/returned, last_order_at,
 * is_best_customer) are never written here: they are facts about orders
 * this business actually processed, and a spreadsheet can't assert them.
 *
 * Phone is normalised through PhoneNumber::format() before hashing, again
 * matching OrderService, so "+212 612-345-678" and "0612345678" resolve to
 * one customer rather than two.
 *
 * PII never reaches a log or an error message (PRD): row-level problems are
 * reported by row number and a reason, never by echoing the offending
 * value back to the caller.
 */
class CustomerImportService
{
    /**
     * Column headers accepted in the uploaded file, lowercased. Public so
     * the downloadable template is generated from the same list this parser
     * matches against, rather than a copy that can drift.
     *
     * @var array<int, string>
     */
    public const HEADERS = ['name', 'phone', 'address', 'city'];

    /**
     * Cap on rows processed per upload. Keeps a single request bounded —
     * the whole import runs synchronously inside one transaction, and the
     * table is per-business, not a bulk data-warehouse load.
     */
    public const MAX_ROWS = 5000;

    /**
     * Parse and import a CSV of customers for one business.
     *
     * @return array{imported: int, updated: int, skipped: int, errors: array<int, array{row: int, reason: string}>}
     */
    public function import(UploadedFile $file, int $businessId): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [
                ['row' => 0, 'reason' => 'The file could not be read.'],
            ]];
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false) {
                return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [
                    ['row' => 0, 'reason' => 'The file is empty.'],
                ]];
            }

            $columns = $this->mapHeader($header);

            if (! isset($columns['phone'])) {
                return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [
                    ['row' => 1, 'reason' => 'A "phone" column is required.'],
                ]];
            }

            return $this->consume($handle, $columns, $businessId);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Resolve the accepted headers to their column positions. Unknown
     * columns are ignored rather than rejected, so a merchant's own export
     * (which usually carries extra columns) imports without hand-editing.
     *
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $label) {
            // Strips a UTF-8 BOM, which Excel writes ahead of the first
            // header and which would otherwise make "name" unmatchable.
            $key = strtolower(trim((string) $label, " \t\n\r\0\x0B\u{FEFF}"));

            if (in_array($key, self::HEADERS, true) && ! isset($columns[$key])) {
                $columns[$key] = $index;
            }
        }

        return $columns;
    }

    /**
     * @param  resource  $handle
     * @param  array<string, int>  $columns
     * @return array{imported: int, updated: int, skipped: int, errors: array<int, array{row: int, reason: string}>}
     */
    private function consume($handle, array $columns, int $businessId): array
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        // Phones already handled in THIS file, so a list that repeats a
        // number doesn't insert it twice and trip the unique index.
        $seen = [];
        $rowNumber = 1;

        DB::transaction(function () use (
            $handle, $columns, $businessId,
            &$imported, &$updated, &$skipped, &$errors, &$seen, &$rowNumber
        ) {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($rowNumber - 1 > self::MAX_ROWS) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'reason' => 'Row limit of '.self::MAX_ROWS.' reached; the rest of the file was not imported.',
                    ];

                    break;
                }

                // A trailing newline yields a row of one empty/null cell.
                if ($this->isBlank($row)) {
                    continue;
                }

                $rawPhone = $this->value($row, $columns, 'phone');
                $phone = PhoneNumber::format($rawPhone);

                if ($phone === null) {
                    $skipped++;
                    $errors[] = ['row' => $rowNumber, 'reason' => 'Missing or invalid phone number.'];

                    continue;
                }

                $hash = hash('sha256', $phone);

                if (isset($seen[$hash])) {
                    $skipped++;
                    $errors[] = ['row' => $rowNumber, 'reason' => 'Duplicate phone number within this file.'];

                    continue;
                }

                $seen[$hash] = true;

                $name = $this->value($row, $columns, 'name');
                $address = $this->value($row, $columns, 'address');
                $city = $this->value($row, $columns, 'city');

                $existing = Customer::where('business_id', $businessId)
                    ->where('phone_hash', $hash)
                    ->first();

                if ($existing) {
                    // Only overwrite a field the file actually carries — a
                    // sparse import must not blank an address the order
                    // history already established.
                    $existing->update(array_filter([
                        'name' => $name,
                        'phone' => $phone,
                        'address' => $address,
                        'city' => $city,
                    ], fn ($value) => $value !== null));

                    $updated++;

                    continue;
                }

                Customer::create([
                    'business_id' => $businessId,
                    'name' => $name ?? '',
                    'phone' => $phone,
                    'phone_hash' => $hash,
                    'address' => $address,
                    'city' => $city,
                    // An imported contact has no order history in this
                    // business yet; these stay zero until real orders land.
                    'orders_count' => 0,
                    'delivered_orders_count' => 0,
                    'returned_orders_count' => 0,
                    'last_order_at' => null,
                    'is_best_customer' => false,
                    'is_blacklisted' => false,
                ]);

                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            // Bounded so a wholly malformed file can't return a payload of
            // thousands of rows; the counts above still reflect everything.
            'errors' => array_slice($errors, 0, 50),
        ];
    }

    /** @param  array<int, string|null>  $row */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $columns
     */
    private function value(array $row, array $columns, string $key): ?string
    {
        if (! isset($columns[$key])) {
            return null;
        }

        $value = trim((string) ($row[$columns[$key]] ?? ''));

        return $value === '' ? null : $value;
    }
}
