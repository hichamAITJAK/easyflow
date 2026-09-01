<?php

namespace App\Events\Commission;

use App\Models\CommissionLedgerEntry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a CommissionLedgerEntry with entry_type 'reversal' is
 * created — a correction against a prior 'earned' entry (append-only
 * ledger: corrections are reversal rows, never edits, per PRD section 10).
 * $entry is the reversal row itself; $entry->reversedEntry resolves the
 * original.
 *
 * Not yet dispatched anywhere: no code creates a reversal entry yet (e.g.
 * clawing back commission on a delivered-then-returned order). This class
 * is the documented seam for that future listener.
 */
class CommissionReversed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly CommissionLedgerEntry $entry) {}
}
