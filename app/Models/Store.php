<?php

namespace App\Models;

use App\Enums\StoreConnectionStatus;
use App\Models\Scopes\BusinessScope;
use Database\Factories\StoreFactory;
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
 * @property int $platform_id
 * @property string $name
 * @property string|null $slug
 * @property string|null $domain
 * @property string|null $logo_url
 * @property string|null $description
 * @property array<string, mixed>|null $meta
 * @property string|null $external_store_id
 * @property string $api_credentials
 * @property string|null $webhook_secret
 * @property StoreConnectionStatus $connection_status
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'platform_id', 'name', 'slug', 'domain', 'logo_url', 'description', 'meta', 'external_store_id', 'api_credentials', 'webhook_secret', 'connection_status', 'last_synced_at'])]
#[Hidden(['api_credentials', 'webhook_secret'])]
#[ScopedBy([BusinessScope::class])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'api_credentials' => 'encrypted',
            'meta' => 'array',
            'connection_status' => StoreConnectionStatus::class,
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<EcommercePlatform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(EcommercePlatform::class, 'platform_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
