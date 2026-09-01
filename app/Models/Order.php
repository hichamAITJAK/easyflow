<?php

namespace App\Models;

use App\Enums\OrderCancelReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderDeliveryStatus;
use App\Enums\OrderReturnReason;
use App\Models\Scopes\BusinessScope;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $reference
 * @property int|null $business_id
 * @property int|null $store_id
 * @property string|null $external_order_id
 * @property string $source_platform
 * @property int|null $assigned_agent_id
 * @property string $customer_name
 * @property string $customer_phone
 * @property string $customer_phone_hash
 * @property string $customer_address
 * @property string|null $customer_city
 * @property string|null $customer_ip_address
 * @property float $total_amount
 * @property float $delivery_cost
 * @property float|null $returned_cost
 * @property float|null $refused_cost
 * @property OrderConfirmationStatus $confirmation_status
 * @property OrderDeliveryStatus|null $delivery_status
 * @property bool $is_delivery_active
 * @property OrderCancelReason|null $cancellation_reason_code
 * @property OrderReturnReason|null $return_reason_code
 * @property string|null $notes
 * @property bool $is_duplicate_flagged
 * @property bool $is_blacklist_flagged
 * @property bool $is_test
 * @property int|null $delivery_account_id
 * @property string|null $courier_tracking_number
 * @property string|null $courier_slug
 * @property string|null $delivery_driver_name
 * @property string|null $delivery_driver_phone
 * @property Carbon|null $shipped_at
 * @property string|null $parcel_note
 * @property string|null $parcel_nature
 * @property bool|null $parcel_open
 * @property bool|null $parcel_fragile
 * @property bool|null $parcel_replace
 * @property array<int, array{ref: string, qnty: int}>|null $parcel_products
 * @property Carbon|null $ready_for_pickup_at
 * @property Carbon|null $return_received_at
 * @property Carbon|null $ordered_at
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['reference', 'business_id', 'store_id', 'external_order_id', 'source_platform', 'assigned_agent_id', 'customer_name', 'customer_phone', 'customer_phone_hash', 'customer_address', 'customer_city', 'customer_ip_address', 'total_amount', 'delivery_cost', 'returned_cost', 'refused_cost', 'confirmation_status', 'delivery_status', 'is_delivery_active', 'cancellation_reason_code', 'return_reason_code', 'notes', 'is_duplicate_flagged', 'is_blacklist_flagged', 'is_test', 'delivery_account_id', 'courier_tracking_number', 'courier_slug', 'delivery_driver_name', 'delivery_driver_phone', 'shipped_at', 'parcel_note', 'parcel_nature', 'parcel_open', 'parcel_fragile', 'parcel_replace', 'parcel_products', 'ready_for_pickup_at', 'return_received_at', 'ordered_at', 'raw_payload'])]
#[Hidden(['customer_name', 'customer_phone', 'customer_address', 'raw_payload'])]
#[ScopedBy([BusinessScope::class])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'customer_name' => 'encrypted',
            'customer_phone' => 'encrypted',
            'customer_address' => 'encrypted',
            'total_amount' => 'decimal:2',
            'delivery_cost' => 'decimal:2',
            'returned_cost' => 'decimal:2',
            'refused_cost' => 'decimal:2',
            'confirmation_status' => OrderConfirmationStatus::class,
            'delivery_status' => OrderDeliveryStatus::class,
            'is_delivery_active' => 'boolean',
            'cancellation_reason_code' => OrderCancelReason::class,
            'return_reason_code' => OrderReturnReason::class,
            'is_duplicate_flagged' => 'boolean',
            'is_blacklist_flagged' => 'boolean',
            'is_test' => 'boolean',
            'shipped_at' => 'datetime',
            'parcel_open' => 'boolean',
            'parcel_fragile' => 'boolean',
            'parcel_replace' => 'boolean',
            'parcel_products' => 'array',
            'ready_for_pickup_at' => 'datetime',
            'return_received_at' => 'datetime',
            'ordered_at' => 'datetime',
            'raw_payload' => 'array',
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
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /** @return BelongsTo<DeliveryAccount, $this> */
    public function deliveryAccount(): BelongsTo
    {
        return $this->belongsTo(DeliveryAccount::class, 'delivery_account_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class);
    }

    /** @return HasMany<CommissionLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class);
    }
}
