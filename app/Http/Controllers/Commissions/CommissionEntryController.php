<?php

namespace App\Http\Controllers\Commissions;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\BuildsTableQuery;
use App\Http\Controllers\Controller;
use App\Models\CommissionLedgerEntry;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Operations\Commissions\CommissionService;
use App\Services\PostHogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CommissionEntryController extends Controller
{
    use BuildsTableQuery;

    /**
     * Invoice statuses, mirroring the `status` column's documented values
     * on the invoices table. Used as an allow-list so an arbitrary query
     * string can't reach the where clause.
     */
    private const INVOICE_STATUSES = ['draft', 'issued', 'paid', 'cancelled'];

    /**
     * Pseudo-status for the entries filter: an entry with no invoice at all.
     * Not an invoice status, so it is matched before the allow-list.
     */
    private const UNINVOICED = 'uninvoiced';

    public function __construct(
        private readonly CommissionService $commissions,
        private readonly PostHogService $posthog,
    ) {}

    /**
     * Display commission entries. Admins/managers see every agent's
     * entries with running totals per agent and can generate/pay invoices;
     * agents see only their own entries, read-only.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = in_array($user->role, [UserRole::SUPER_ADMIN, UserRole::ADMIN], true);

        $applyFilters = fn ($query) => $this->applyEntryFilters($query, $request, $isAdmin);

        $entries = $applyFilters(CommissionLedgerEntry::query())
            ->with(['user:id,name,avatar', 'order:id,reference', 'invoice:id,invoice_number,status'])
            ->latest('commission_ledger_entries.created_at')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        // Running totals per agent must reflect every filtered entry, not just
        // the current page, so they're aggregated separately from the paginated list.
        $totals = $applyFilters(CommissionLedgerEntry::query())
            ->leftJoin('invoices', 'invoices.id', '=', 'commission_ledger_entries.invoice_id')
            ->selectRaw("commission_ledger_entries.user_id, sum(commission_ledger_entries.amount) as earned, sum(case when invoices.status = 'paid' then commission_ledger_entries.amount else 0 end) as paid")
            ->groupBy('commission_ledger_entries.user_id')
            // toBase(): these are synthetic aggregate rows (earned/paid),
            // not CommissionLedgerEntry models — hydrating them as models
            // would fake attributes the model doesn't have.
            ->toBase()
            ->get()
            ->keyBy('user_id');

        $agents = $isAdmin
            ? User::where('business_id', $user->business_id)
                ->whereIn('role', [UserRole::CONFIRMATION_AGENT, UserRole::FULFILMENT_AGENT, UserRole::CREATIVES_EDITOR])
                ->orderBy('name')
                ->get(['id', 'name', 'avatar'])
            : collect([$user->only(['id', 'name', 'avatar'])]);

        // Its own key, not the entries tab's `status`: the two tabs filter
        // independently, and sharing one key would make a single control
        // silently re-scope both tables at once.
        $invoiceStatus = $request->string('invoice_status')->toString();

        // An invoice always belongs to exactly one agent, so the owner is
        // eager loaded for the table's agent column rather than resolved
        // per row.
        $invoiceAgentId = $request->integer('invoice_agent');

        $invoices = Invoice::where('business_id', $user->business_id)
            ->with('user:id,name,avatar')
            ->when(! $isAdmin, fn ($query) => $query->where('user_id', $user->id))
            ->when(
                in_array($invoiceStatus, self::INVOICE_STATUSES, true),
                fn ($query) => $query->where('status', $invoiceStatus)
            )
            // Agents only ever see their own invoices, so the filter is
            // admin-only — it would be a no-op at best otherwise.
            ->when(
                $isAdmin && $invoiceAgentId > 0,
                fn ($query) => $query->where('user_id', $invoiceAgentId)
            )
            ->latest('created_at')
            ->paginate(
                $this->resolvePerPage($request, perPageKey: 'invoices_per_page'),
                ['*'],
                'invoices_page',
            )
            ->withQueryString();

        // Drives the bulk action's confirmation copy. Counted over the whole
        // filtered set, not the current page, because that is the scope the
        // bulk action operates on — showing a page-sized number next to an
        // action that invoices everything would understate what it does.
        $invoiceableCount = $isAdmin
            ? $applyFilters(CommissionLedgerEntry::query())
                ->whereNull('commission_ledger_entries.invoice_id')
                ->count()
            : 0;

        // One invoice is created per distinct agent, so the admin is told how
        // many documents they are about to produce, not just how many rows
        // get consumed.
        $invoiceableAgentCount = $invoiceableCount > 0
            ? $applyFilters(CommissionLedgerEntry::query())
                ->whereNull('commission_ledger_entries.invoice_id')
                ->distinct()
                ->count('commission_ledger_entries.user_id')
            : 0;

        return Inertia::render('commission-entries/index', [
            'entries' => $entries,
            'invoiceableCount' => $invoiceableCount,
            'invoiceableAgentCount' => $invoiceableAgentCount,
            'totalsByAgent' => $totals->map(fn ($row) => [
                'earned' => (float) $row->earned,
                'paid' => (float) $row->paid,
                'pending' => (float) $row->earned - (float) $row->paid,
            ]),
            'agents' => $agents,
            'invoices' => $invoices,
            'isAdmin' => $isAdmin,
            'filters' => $request->only(['per_page', 'agent_id', 'date_from', 'date_to', 'min_amount', 'max_amount', 'status', 'invoice_status', 'invoices_per_page']),
        ]);
    }

    /**
     * The `agent_id` / `date_from` / `date_to` / `min_amount` / `max_amount`
     * filter set behind the entries list.
     *
     * Shared by index() and generateInvoicesFromFilters() on purpose: the
     * bulk action invoices whatever the current filter selects, so if the
     * two ever read the filters differently the confirmation count would
     * stop matching what actually gets invoiced.
     *
     * @param  Builder<CommissionLedgerEntry>  $query
     * @return Builder<CommissionLedgerEntry>
     */
    private function applyEntryFilters(Builder $query, Request $request, bool $isAdmin): Builder
    {
        $user = $request->user();
        $agentId = $request->integer('agent_id');
        $dateFrom = $request->date('date_from');
        $dateTo = $request->date('date_to');
        $minAmount = $request->input('min_amount');
        $maxAmount = $request->input('max_amount');
        $status = $request->string('status')->toString();

        return $query
            ->where('commission_ledger_entries.business_id', $user->business_id)
            ->when(! $isAdmin, fn ($q) => $q->where('commission_ledger_entries.user_id', $user->id))
            ->when($isAdmin && $agentId, fn ($q) => $q->where('commission_ledger_entries.user_id', $agentId))
            ->when($dateFrom, fn ($q, $date) => $q->whereDate('commission_ledger_entries.created_at', '>=', $date))
            ->when($dateTo, fn ($q, $date) => $q->whereDate('commission_ledger_entries.created_at', '<=', $date))
            ->when(is_numeric($minAmount), fn ($q) => $q->where('commission_ledger_entries.amount', '>=', $minAmount))
            ->when(is_numeric($maxAmount), fn ($q) => $q->where('commission_ledger_entries.amount', '<=', $maxAmount))
            // An entry has no status of its own — what the Status column shows
            // is the parent invoice's, or "Not invoiced" when there is none.
            // `uninvoiced` is the operationally useful one: it is exactly the
            // set the bulk action consumes.
            ->when(
                $status === self::UNINVOICED,
                fn ($q) => $q->whereNull('commission_ledger_entries.invoice_id'),
                fn ($q) => $q->when(
                    in_array($status, self::INVOICE_STATUSES, true),
                    fn ($q) => $q->whereHas(
                        'invoice',
                        fn ($invoice) => $invoice->where('status', $status)
                    )
                )
            );
    }

    /**
     * Invoice every uninvoiced entry matching the current filters, rather
     * than only the rows the admin could tick on the page they happen to be
     * looking at.
     *
     * The entry ids are resolved server-side from the same filters the list
     * uses, not posted from the client: a filter can match thousands of
     * entries, which would overflow a form body, and a client-supplied id
     * list is a set of ids to trust rather than a filter to re-evaluate.
     */
    public function generateInvoicesFromFilters(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage-users'), 403);

        $entryIds = $this->applyEntryFilters(CommissionLedgerEntry::query(), $request, isAdmin: true)
            ->whereNull('commission_ledger_entries.invoice_id')
            ->pluck('commission_ledger_entries.id')
            ->all();

        if ($entryIds === []) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('No uninvoiced entries match these filters.'),
            ]);

            return to_route('commission-entries.index', $request->query());
        }

        try {
            $invoices = $this->commissions->generateInvoices($request->user()->business_id, $entryIds);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $this->posthog->capture((string) $request->user()->id, 'commission_invoice_generated', [
            'agent_ids' => $invoices->pluck('user_id')->all(),
            'invoice_count' => $invoices->count(),
            'entry_count' => count($entryIds),
            'business_id' => $request->user()->business_id,
            'source' => 'filters',
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $invoices->count() > 1
                ? __(':invoices invoices generated from :entries entries.', [
                    'invoices' => $invoices->count(),
                    'entries' => count($entryIds),
                ])
                : __('Invoice generated from :count entries.', ['count' => count($entryIds)]),
        ]);

        // Filters are preserved so the admin lands back on the same view they
        // acted on, now showing those entries as invoiced.
        return to_route('commission-entries.index', $request->query());
    }

    /**
     * Bundle the given unpaid, uninvoiced ledger entries into one invoice
     * per agent they belong to (a selection spanning several agents
     * produces several invoices).
     */
    public function generateInvoice(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage-users'), 403);

        $data = $request->validate([
            'entry_ids' => ['required', 'array', 'min:1'],
            'entry_ids.*' => [
                Rule::exists(CommissionLedgerEntry::class, 'id')
                    ->where('business_id', $request->user()->business_id)
                    ->whereNull('invoice_id'),
            ],
        ]);

        try {
            $invoices = $this->commissions->generateInvoices($request->user()->business_id, $data['entry_ids']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        // PostHog: Track commission invoice generation
        $this->posthog->capture((string) $request->user()->id, 'commission_invoice_generated', [
            'agent_ids' => $invoices->pluck('user_id')->all(),
            'invoice_count' => $invoices->count(),
            'entry_count' => count($data['entry_ids']),
            'business_id' => $request->user()->business_id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $invoices->count() > 1
                ? __(':count invoices generated.', ['count' => $invoices->count()])
                : __('Invoice generated.'),
        ]);

        return back(fallback: route('commission-entries.index'));
    }

    /**
     * Mark an invoice as paid.
     */
    public function markInvoicePaid(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($request->user()->can('manage-users'), 403);
        abort_unless($invoice->business_id === $request->user()->business_id, 403);

        $this->commissions->markInvoicePaid($invoice);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice marked as paid.')]);

        return back(fallback: route('commission-entries.index'));
    }

    /**
     * Download an invoice as a PDF.
     *
     * Line items are read through the invoice's own ledger entries rather
     * than re-deriving them from a period query: entries are stamped with
     * `invoice_id` at generation time, so this renders exactly what was
     * invoiced even if later entries fall inside the same date range.
     */
    public function downloadInvoice(Request $request, Invoice $invoice): HttpResponse
    {
        $this->authorizeInvoiceRead($request, $invoice);

        $invoice->load([
            'user:id,name,email',
            'business:id,name',
            'ledgerEntries' => fn ($query) => $query->orderBy('id'),
            'ledgerEntries.order:id,reference',
        ]);

        $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $invoice]);

        // Filename carries the invoice number, so a folder of these stays
        // sortable and identifiable without opening each one.
        return $pdf->download("{$invoice->invoice_number}.pdf");
    }

    /**
     * One invoice with its line items, for the preview dialog.
     *
     * Returned as JSON and fetched on demand rather than shipped with the
     * index: line items are only ever needed for the one invoice being
     * looked at, and eager loading them for every row would grow the page
     * payload with the ledger.
     *
     * Reads the same relations as the PDF so the preview and the downloaded
     * file cannot disagree about what was invoiced.
     */
    public function showInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoiceRead($request, $invoice);

        $invoice->load([
            'user:id,name,email',
            'business:id,name',
            'ledgerEntries' => fn ($query) => $query->orderBy('id'),
            'ledgerEntries.order:id,reference',
        ]);

        return response()->json([
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'total_amount' => $invoice->total_amount,
            'notes' => $invoice->notes,
            'business_name' => $invoice->business?->name,
            'agent' => $invoice->user ? [
                'name' => $invoice->user->name,
                'email' => $invoice->user->email,
            ] : null,
            'entries' => $invoice->ledgerEntries->map(fn (CommissionLedgerEntry $entry) => [
                'id' => $entry->id,
                // A bonus entry has no order — it is earned over a period,
                // so its own description names it instead.
                'reference' => $entry->order?->reference,
                'description' => $entry->description,
                // CommissionLedgerEntry sets $timestamps = false and casts no
                // dates, so created_at arrives as a raw string.
                'created_at' => $entry->created_at
                    ? Carbon::parse($entry->created_at)->toDateString()
                    : null,
                'entry_type' => $entry->entry_type,
                'amount' => $entry->amount,
            ])->values(),
        ]);
    }

    /**
     * Reading an invoice (preview, PDF) is for the admin or the person it
     * was issued to: an agent or editor can always see their own pay
     * statement, never someone else's.
     */
    private function authorizeInvoiceRead(Request $request, Invoice $invoice): void
    {
        $user = $request->user();

        abort_unless($invoice->business_id === $user->business_id, 403);
        abort_unless($user->can('manage-users') || $invoice->user_id === $user->id, 403);
    }
}
