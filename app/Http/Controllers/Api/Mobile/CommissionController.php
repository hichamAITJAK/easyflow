<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\CommissionLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile Confirmation agent navigator's Commission screen backend
 * (PRD section 6.6 / 7 — commission_ledger_entries). Read-only: entries
 * are system-generated on OrderConfirmed/delivered (see
 * CalculateAgentCommission), never created or edited by an agent here —
 * this is a statement view, not a data-entry form. Always scoped to the
 * caller's own user_id; unlike CommissionEntryController's web admin view,
 * there is no cross-agent visibility on mobile.
 */
class CommissionController extends Controller
{
    /**
     * List the caller's own commission entries, optionally restricted to a
     * date range. Newest first, since an agent checking this mid-shift
     * cares most about what just landed.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $entries = CommissionLedgerEntry::query()
            ->where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->with(['order:id,reference', 'invoice:id,status'])
            ->when($request->date('date_from'), fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($request->date('date_to'), fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('created_at')
            ->get();

        return response()->json(['entries' => $entries->map(fn (CommissionLedgerEntry $entry) => $this->entryPayload($entry))]);
    }

    /**
     * Total earned this period (reversals subtract, matching the ledger's
     * own append-only reversal-entry convention — never a mutated row).
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $total = CommissionLedgerEntry::query()
            ->where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->when($request->date('date_from'), fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($request->date('date_to'), fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->sum('amount');

        return response()->json(['total' => (float) $total]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entryPayload(CommissionLedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'order_reference' => $entry->order?->reference,
            'amount' => (float) $entry->amount,
            'entry_type' => $entry->entry_type,
            // No invoice yet = still pending settlement; otherwise mirror
            // the invoice's own status (draft/issued/paid/cancelled) so the
            // agent sees exactly where it stands, not a collapsed boolean.
            'settlement_status' => optional($entry->invoice)->status ?? 'pending',
            'created_at' => $entry->created_at,
        ];
    }
}
