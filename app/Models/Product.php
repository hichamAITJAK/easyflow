<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int|null $store_id
 * @property string|null $external_product_id
 * @property string $name
 * @property string|null $sku
 * @property string|null $description
 * @property string|null $description_html
 * @property float|null $price
 * @property int|null $inventory_quantity
 * @property string|null $public_url
 * @property string|null $thumbnail
 * @property array<int, string>|null $tags
 * @property bool|null $status
 * @property bool $is_active
 * @property bool $is_test
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'store_id', 'external_product_id', 'name', 'sku', 'description', 'description_html', 'price', 'inventory_quantity', 'public_url', 'thumbnail', 'tags', 'status', 'is_active', 'is_test'])]
#[ScopedBy([BusinessScope::class])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'inventory_quantity' => 'integer',
            'tags' => 'array',
            'status' => 'boolean',
            'is_active' => 'boolean',
            'is_test' => 'boolean',
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

    /** @return HasMany<AgentScope, $this> */
    public function agentScopes(): HasMany
    {
        return $this->hasMany(AgentScope::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<ProductOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * A manually-added product's image gallery, ordered by position. The
     * image at position 0 doubles as `thumbnail` (kept in sync by
     * ProductImageWriter) — never eager-load both to redundantly render the
     * same first image twice.
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /**
     * Resolve the thumbnail's public URL from its stored value: a manually
     * uploaded file lives on the public storage disk as a relative path
     * (e.g. "product-images/xyz.jpg"), while a synced product's thumbnail is
     * already the platform's own absolute URL — passed through unchanged.
     *
     * @return Attribute<string, never>
     */
    protected function thumbnail(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => match (true) {
                $value === null => null,
                str_starts_with($value, 'http://'), str_starts_with($value, 'https://') => $value,
                default => '/storage/'.$value,
            },
        );
    }
}
