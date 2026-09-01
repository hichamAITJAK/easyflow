<?php

namespace App\Events\Commission;

use App\Models\CommissionLedgerEntry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a CommissionLedgerEntry with entry_type 'earned' is created
 * (currently: CalculateAgentCommission on OrderConfirmed). The seam for
 * keeping daily_stats_summary.commission_total in sync without coupling
 * commission calculation itself to the stats pipeline.
 */
class CommissionEarned
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly CommissionLedgerEntry $entry) {}
}
