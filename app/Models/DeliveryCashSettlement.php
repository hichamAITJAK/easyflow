<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One hand-over of COD cash from a delivery guy to the business.
 *
 * @property int $id
 * @property int $business_id
 * @property int $user_id
 * @property int|null $settled_by_user_id
 * @property float $amount
 * @property int $parcels_count
 * @property string|null $note
 * @property Carbon|null $created_at
 */
#[Fillable(['business_id', 'user_id', 'settled_by_user_id', 'amount', 'parcels_count', 'note'])]
#[ScopedBy([BusinessScope::class])]
class DeliveryCashSettlement extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'parcels_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function deliveryGuy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
