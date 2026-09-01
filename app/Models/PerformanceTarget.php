<?php

namespace App\Models;

use App\Enums\PerformanceMetric;
use App\Enums\PerformanceTargetPeriod;
use App\Models\Scopes\BusinessScope;
use Database\Factories\PerformanceTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int|null $user_id
 * @property PerformanceMetric $metric
 * @property float $target_percentage
 * @property float|null $bonus_amount
 * @property PerformanceTargetPeriod $period
 * @property int $min_orders_for_evaluation
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'user_id', 'metric', 'target_percentage', 'bonus_amount', 'period', 'min_orders_for_evaluation', 'is_active'])]
#[ScopedBy([BusinessScope::class])]
class PerformanceTarget extends Model
{
    /** @use HasFactory<PerformanceTargetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metric' => PerformanceMetric::class,
            'target_percentage' => 'decimal:2',
            'bonus_amount' => 'decimal:2',
            'period' => PerformanceTargetPeriod::class,
            'min_orders_for_evaluation' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The agent this target applies to. Null means it's the business-wide
     * default target for this metric, applied to any agent without a
     * more specific target row of their own.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
