<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One image in a manually-added product's gallery. Ordered by `position`;
 * the image at position 0 doubles as the product's thumbnail (kept in sync
 * by ProductImageWriter).
 *
 * @property int $id
 * @property int $business_id
 * @property int $product_id
 * @property string $path
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'product_id', 'path', 'position'])]
#[ScopedBy([BusinessScope::class])]
class ProductImage extends Model
{
    protected function casts(): array
    {
        return [
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

    /**
     * Resolve the image's public URL from its stored relative path (e.g.
     * "product-images/xyz.jpg"), same convention as Product::thumbnail and
     * ProductVariant::image.
     *
     * @return Attribute<string, never>
     */
    protected function path(): Attribute
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
