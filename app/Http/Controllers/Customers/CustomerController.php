<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Concerns\BuildsTableQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportCustomersRequest;
use App\Models\Customer;
use App\Models\CustomerBlacklistEntry;
use App\Services\Operations\Customers\CustomerImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    use BuildsTableQuery;

    /**
     * Columns the customers table may be sorted by, keyed to their allowed
     * query-string `sort` value.
     */
    private const SORTABLE = ['name', 'city', 'orders_count', 'delivered_orders_count', 'returned_orders_count', 'last_order_at'];

    /**
     * Columns searched by the `search` query param. Encrypted columns can't
     * be matched with LIKE, so search is limited to plaintext columns.
     */
    private const SEARCHABLE = ['city'];

    /**
     * Display the customers list, optionally scoped to best or
     * blacklisted customers.
     */
    public function index(Request $request): Response
    {
        $businessId = $request->user()->business_id;

        $scopedCustomers = fn () => Customer::where('business_id', $businessId);

        $query = $scopedCustomers()
            ->when($request->boolean('best'), fn ($query) => $query->where('is_best_customer', true))
            ->when($request->boolean('blacklisted'), fn ($query) => $query->where('is_blacklisted', true));

        $query = $this->applySearch($query, $request, self::SEARCHABLE);
        $query = $this->applySort($query, $request, self::SORTABLE, default: 'last_order_at');

        $customers = $query->paginate($this->resolvePerPage($request))->withQueryString();

        $customers->getCollection()->each->makeVisible(['name', 'phone', 'address']);

        return Inertia::render('customers/index', [
            'customers' => $customers,
            'metrics' => [
                'total' => $scopedCustomers()->count(),
                'best' => $scopedCustomers()->where('is_best_customer', true)->count(),
                'blacklisted' => $scopedCustomers()->where('is_blacklisted', true)->count(),
            ],
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page', 'best', 'blacklisted']),
            // Set only on the redirect back from an import, so the page can
            // show the per-row outcome the toast has no room for.
            'importResult' => fn () => $request->session()->get('importResult'),
        ]);
    }

    /**
     * Display the blacklisted phone numbers list.
     */
    public function blacklist(Request $request): Response
    {
        $businessId = $request->user()->business_id;

        $search = $request->string('search')->trim()->toString();

        $query = CustomerBlacklistEntry::where('business_id', $businessId)
            ->with('addedByUser:id,name')
            ->when($search !== '', fn ($query) => $query->where('phone_hash', hash('sha256', $search)));

        $query = $this->applySort($query, $request, ['created_at'], default: 'created_at');

        $entries = $query->paginate($this->resolvePerPage($request))->withQueryString();

        $entries->getCollection()->each->makeVisible('phone_encrypted');

        return Inertia::render('customers/blacklist', [
            'entries' => $entries,
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
        ]);
    }

    /**
     * A starter CSV with the accepted headers and one example row, so an
     * operator can fill in their list rather than guess the format. Built
     * from CustomerImportService::HEADERS, so the two can't drift apart.
     */
    public function importTemplate(): StreamedResponse
    {
        $headers = CustomerImportService::HEADERS;

        // Obviously-fake sample data — never a real customer's details.
        $example = [
            'name' => 'Amina Benali',
            'phone' => '0612345678',
            'address' => '12 Rue Hassan II',
            'city' => 'Casablanca',
        ];

        return response()->streamDownload(function () use ($headers, $example) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // Excel reads a UTF-8 CSV as the local codepage unless a BOM
            // leads the file, which mangles accented Moroccan names.
            fwrite($handle, "\u{FEFF}");

            fputcsv($handle, $headers);
            fputcsv($handle, array_map(fn (string $key) => $example[$key], $headers));

            fclose($handle);
        }, 'easyflow-customers-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Import a CSV of customers into the caller's own business. Rows match
     * existing customers on their phone hash, so re-importing an overlapping
     * list updates rather than duplicates — see CustomerImportService.
     */
    public function import(ImportCustomersRequest $request, CustomerImportService $importer): RedirectResponse
    {
        $result = $importer->import(
            $request->file('file'),
            $request->user()->business_id,
        );

        // The row-level reasons carry no PII (row number + reason only), so
        // they are safe to flash back for the operator to fix their file.
        $request->session()->flash('importResult', $result);

        Inertia::flash('toast', [
            'type' => $result['imported'] + $result['updated'] > 0 ? 'success' : 'error',
            'message' => __(':imported added, :updated updated, :skipped skipped.', [
                'imported' => $result['imported'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
            ]),
        ]);

        return back(fallback: route('customers.index'));
    }
}
