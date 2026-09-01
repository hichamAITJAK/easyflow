<?php

namespace App\Models;

use App\Enums\DeliveryAccountStatus;
use App\Models\Scopes\BusinessScope;
use Database\Factories\DeliveryAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $courier_id
 * @property int|null $collect_city_id
 * @property string $label
 * @property string $api_credentials
 * @property string|null $webhook_secret
 * @property bool $is_default
 * @property DeliveryAccountStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'courier_id', 'collect_city_id', 'label', 'api_credentials', 'webhook_secret', 'is_default', 'status'])]
#[Hidden(['api_credentials', 'webhook_secret'])]
#[ScopedBy([BusinessScope::class])]
class DeliveryAccount extends Model
{
    /** @use HasFactory<DeliveryAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'api_credentials' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_default' => 'boolean',
            'status' => DeliveryAccountStatus::class,
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<DeliveryCourrier, $this> */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourrier::class, 'courier_id');
    }

    /** @return BelongsTo<DeleveryCourrierCity, $this> */
    public function collectCity(): BelongsTo
    {
        return $this->belongsTo(DeleveryCourrierCity::class, 'collect_city_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
