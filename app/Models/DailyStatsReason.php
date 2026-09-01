<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Database\Factories\DailyStatsReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pre-computed daily counts of cancellation/return reason codes, scoped the
 * same way as DailyStatsSummary (business-wide, store-scoped, or
 * agent-scoped rows) — kept as its own table rather than columns on
 * DailyStatsSummary because the reason-code space (18 cases across
 * OrderCancelReason/OrderReturnReason) doesn't fit fixed columns and
 * Eloquent's increment() can't atomically bump a value inside a JSON blob.
 *
 * @property int $id
 * @property int $business_id
 * @property int|null $store_id
 * @property int|null $agent_id
 * @property Carbon $stat_date
 * @property string $reason_type
 * @property string $reason_code
 * @property int $count
 * @property Carbon|null $created_at
 */
#[Fillable(['business_id', 'store_id', 'agent_id', 'stat_date', 'reason_type', 'reason_code', 'count'])]
#[ScopedBy([BusinessScope::class])]
class DailyStatsReason extends Model
{
    /** @use HasFactory<DailyStatsReasonFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'daily_stats_reasons';

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'count' => 'integer',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
