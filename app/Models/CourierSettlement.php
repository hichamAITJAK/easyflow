<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Database\Factories\CourierSettlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $delivery_account_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property float $expected_amount
 * @property float|null $actual_amount
 * @property float|null $difference_amount
 * @property string $status
 * @property Carbon|null $reconciled_at
 * @property int|null $reconciled_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['business_id', 'delivery_account_id', 'period_start', 'period_end', 'expected_amount', 'actual_amount', 'difference_amount', 'status', 'reconciled_at', 'reconciled_by', 'notes'])]
#[ScopedBy([BusinessScope::class])]
class CourierSettlement extends Model
{
    /** @use HasFactory<CourierSettlementFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'expected_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<DeliveryAccount, $this> */
    public function deliveryAccount(): BelongsTo
    {
        return $this->belongsTo(DeliveryAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
