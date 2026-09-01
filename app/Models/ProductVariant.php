<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $product_id
 * @property string|null $external_variant_id
 * @property string|null $sku
 * @property string|null $image
 * @property float|null $price
 * @property int|null $inventory_quantity
 * @property bool $is_available
 * @property int|null $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'product_id', 'external_variant_id', 'sku', 'image', 'price', 'inventory_quantity', 'is_available', 'position'])]
#[ScopedBy([BusinessScope::class])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'inventory_quantity' => 'integer',
            'is_available' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsToMany<ProductOptionValue, $this> */
    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_variant_option_values');
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Resolve the image's public URL from its stored value: a manually
     * uploaded file lives on the public storage disk as a relative path
     * (e.g. "variant-images/xyz.jpg"), while a synced variant's image is
     * already the platform's own absolute URL — passed through unchanged.
     *
     * @return Attribute<string, never>
     */
    protected function image(): Attribute
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
