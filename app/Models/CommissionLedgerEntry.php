<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Database\Factories\CommissionLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $user_id
 * @property int $order_id
 * @property int|null $commission_rule_id
 * @property int|null $invoice_id
 * @property float $amount
 * @property int|null $performance_target_id
 * @property int|null $content_request_id
 * @property string|null $description
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string $entry_type
 * @property int|null $reversed_entry_id
 * @property Carbon|null $created_at
 */
#[Fillable(['business_id', 'user_id', 'order_id', 'commission_rule_id', 'performance_target_id', 'content_request_id', 'invoice_id', 'amount', 'description', 'period_start', 'period_end', 'entry_type', 'reversed_entry_id'])]
#[ScopedBy([BusinessScope::class])]
class CommissionLedgerEntry extends Model
{
    /** @use HasFactory<CommissionLedgerEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'commission_ledger_entries';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<CommissionRule, $this> */
    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    /** The validated content request a creative pay row came from. @return BelongsTo<ContentRequest, $this> */
    public function contentRequest(): BelongsTo
    {
        return $this->belongsTo(ContentRequest::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }

    /** @return HasMany<self, $this> */
    public function reversalEntries(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_entry_id');
    }
}
