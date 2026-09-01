<?php

namespace App\Services\Operations\Commissions;

use App\Models\CommissionLedgerEntry;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Commission/invoicing business logic shared by every client (web, mobile).
 * Methods here take and return plain models/scalars/arrays only — never a
 * Request, never an HTTP response. Callers (controllers) are responsible
 * for input validation, authorization, and shaping the result for their
 * transport.
 */
class CommissionService
{
    /**
     * Bundle the given unpaid, uninvoiced ledger entries for one agent into
     * a new draft invoice.
     *
     * @param  array<int, int>  $entryIds
     *
     * @throws InvalidArgumentException if none of the given entries belong to the agent.
     */
    public function generateInvoice(int $businessId, int $userId, array $entryIds): Invoice
    {
        $entries = CommissionLedgerEntry::whereIn('id', $entryIds)
            ->where('user_id', $userId)
            ->get();

        if ($entries->isEmpty()) {
            throw new InvalidArgumentException('None of the selected entries belong to this agent.');
        }

        $invoice = Invoice::create([
            'business_id' => $businessId,
            'user_id' => $userId,
            'invoice_number' => 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'period_start' => $entries->min('created_at'),
            'period_end' => $entries->max('created_at'),
            'total_amount' => $entries->sum('amount'),
            'status' => 'issued',
        ]);

        CommissionLedgerEntry::whereIn('id', $entries->pluck('id'))->update(['invoice_id' => $invoice->id]);

        return $invoice;
    }

    /**
     * Bundle the given unpaid, uninvoiced ledger entries into one invoice
     * per agent they actually belong to (a single invoice can't span
     * agents), so a multi-agent selection produces several invoices at once.
     *
     * @param  array<int, int>  $entryIds
     * @return Collection<int, Invoice>
     *
     * @throws InvalidArgumentException if none of the given entries belong to this business.
     */
    public function generateInvoices(int $businessId, array $entryIds): Collection
    {
        $entriesByAgent = CommissionLedgerEntry::whereIn('id', $entryIds)
            ->where('business_id', $businessId)
            ->whereNull('invoice_id')
            ->get()
            ->groupBy('user_id');

        if ($entriesByAgent->isEmpty()) {
            throw new InvalidArgumentException('None of the selected entries can be invoiced.');
        }

        return $entriesByAgent->map(
            fn (Collection $entries, int $userId) => $this->generateInvoice($businessId, $userId, $entries->pluck('id')->all())
        )->values();
    }

    /**
     * Mark an invoice as paid.
     */
    public function markInvoicePaid(Invoice $invoice): Invoice
    {
        $invoice->update(['status' => 'paid']);

        return $invoice;
    }
}
