<?php

namespace App\Models;

use App\Enums\OrderDeliveryStatus;
use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a parcel's journey as the courier reported it.
 *
 * @property int $id
 * @property int $business_id
 * @property int $order_id
 * @property string $status
 * @property OrderDeliveryStatus|null $delivery_status
 * @property string|null $payment_state
 * @property Carbon|null $happened_at
 * @property Carbon|null $created_at
 */
#[Fillable(['business_id', 'order_id', 'status', 'delivery_status', 'payment_state', 'happened_at'])]
#[ScopedBy([BusinessScope::class])]
class CourierTrackingEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'delivery_status' => OrderDeliveryStatus::class,
            'happened_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
