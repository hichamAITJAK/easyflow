<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property string $phone
 * @property string $phone_hash
 * @property string|null $address
 * @property string|null $city
 * @property int $orders_count
 * @property int $delivered_orders_count
 * @property int $returned_orders_count
 * @property Carbon|null $last_order_at
 * @property bool $is_best_customer
 * @property bool $is_blacklisted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'name', 'phone', 'phone_hash', 'address', 'city', 'orders_count', 'delivered_orders_count', 'returned_orders_count', 'last_order_at', 'is_best_customer', 'is_blacklisted'])]
#[Hidden(['name', 'phone', 'address'])]
#[ScopedBy([BusinessScope::class])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'phone' => 'encrypted',
            'address' => 'encrypted',
            'orders_count' => 'integer',
            'delivered_orders_count' => 'integer',
            'returned_orders_count' => 'integer',
            'last_order_at' => 'datetime',
            'is_best_customer' => 'boolean',
            'is_blacklisted' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
