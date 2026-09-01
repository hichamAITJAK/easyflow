<?php

namespace App\Models;

use App\Enums\CommissionAmountType;
use App\Enums\CommissionPaymentMode;
use App\Enums\SalaryPeriod;
use App\Models\Scopes\BusinessScope;
use Database\Factories\CommissionRuleFactory;
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
 * @property int|null $user_id
 * @property CommissionPaymentMode $payment_mode
 * @property int|null $store_id
 * @property int|null $product_id
 * @property string|null $trigger_status
 * @property CommissionAmountType|null $amount_type
 * @property float|null $amount
 * @property float|null $salary_amount
 * @property SalaryPeriod|null $salary_period
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'user_id', 'payment_mode', 'store_id', 'product_id', 'trigger_status', 'amount_type', 'amount', 'salary_amount', 'salary_period', 'is_active'])]
#[ScopedBy([BusinessScope::class])]
class CommissionRule extends Model
{
    /** @use HasFactory<CommissionRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payment_mode' => CommissionPaymentMode::class,
            'amount_type' => CommissionAmountType::class,
            'amount' => 'decimal:2',
            'salary_amount' => 'decimal:2',
            'salary_period' => SalaryPeriod::class,
            'is_active' => 'boolean',
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

    /**
     * The store this override applies to. Null on the agent's default rule
     * and on product-scoped overrides.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * The product this override applies to. Null on the agent's default
     * rule and on store-scoped overrides.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<CommissionLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class);
    }
}
