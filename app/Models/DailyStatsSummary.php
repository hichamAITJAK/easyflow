<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Database\Factories\DailyStatsSummaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int|null $store_id
 * @property string|null $store_name
 * @property int|null $product_id
 * @property int|null $agent_id
 * @property int|null $delivery_account_id
 * @property Carbon $stat_date
 * @property int $orders_count
 * @property int $assigned_count
 * @property int $confirmed_count
 * @property int $submitted_to_courier_count
 * @property int $delivered_count
 * @property int $returned_count
 * @property int $cancelled_count
 * @property int $refused_count
 * @property float|null $confirmation_rate
 * @property float|null $delivery_success_rate
 * @property float|null $revenue_confirmed
 * @property float|null $revenue_delivered
 * @property float|null $commission_total
 * @property int|null $confirm_seconds_total
 * @property int|null $delivery_seconds_total
 * @property Carbon|null $created_at
 */
#[Fillable(['business_id', 'store_id', 'store_name', 'product_id', 'agent_id', 'delivery_account_id', 'stat_date', 'orders_count', 'assigned_count', 'confirmed_count', 'submitted_to_courier_count', 'delivered_count', 'returned_count', 'cancelled_count', 'refused_count', 'confirmation_rate', 'delivery_success_rate', 'revenue_confirmed', 'revenue_delivered', 'commission_total', 'confirm_seconds_total', 'delivery_seconds_total'])]
#[ScopedBy([BusinessScope::class])]
class DailyStatsSummary extends Model
{
    /** @use HasFactory<DailyStatsSummaryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'daily_stats_summary';

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'orders_count' => 'integer',
            'assigned_count' => 'integer',
            'confirmed_count' => 'integer',
            'submitted_to_courier_count' => 'integer',
            'delivered_count' => 'integer',
            'returned_count' => 'integer',
            'cancelled_count' => 'integer',
            'refused_count' => 'integer',
            'confirmation_rate' => 'float',
            'delivery_success_rate' => 'float',
            'revenue_confirmed' => 'decimal:2',
            'revenue_delivered' => 'decimal:2',
            'commission_total' => 'decimal:2',
            'confirm_seconds_total' => 'integer',
            'delivery_seconds_total' => 'integer',
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<DeliveryAccount, $this> */
    public function deliveryAccount(): BelongsTo
    {
        return $this->belongsTo(DeliveryAccount::class);
    }
}
