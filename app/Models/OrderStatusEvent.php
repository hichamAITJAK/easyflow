<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Database\Factories\OrderStatusEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $changed_by_user_id
 * @property string|null $note
 * @property Carbon|null $created_at
 */
#[Fillable(['business_id', 'order_id', 'from_status', 'to_status', 'changed_by_user_id', 'note'])]
#[ScopedBy([BusinessScope::class])]
class OrderStatusEvent extends Model
{
    /** @use HasFactory<OrderStatusEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'order_status_events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
