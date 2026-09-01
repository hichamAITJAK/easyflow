<?php

namespace App\Models;

use Database\Factories\EcommercePlatformFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $logo_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'description', 'logo_url'])]
class EcommercePlatform extends Model
{
    /** @use HasFactory<EcommercePlatformFactory> */
    use HasFactory;

    /**
     * Get the stores running on this platform.
     *
     * @return HasMany<Store, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class, 'platform_id');
    }

    /**
     * Resolve the logo's public URL from its stored value: logos uploaded
     * through the super admin panel live on the public storage disk, while
     * the seeded ones are repo assets under public/assets. Absolute URLs
     * are left alone.
     *
     * Host-relative for the same reason as User::avatar() — a baked-in
     * APP_URL silently 404s the image when the app is served elsewhere.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => match (true) {
                $value === null || $value === '' => null,
                str_starts_with($value, 'http://'),
                str_starts_with($value, 'https://'),
                str_starts_with($value, '/') => $value,
                str_starts_with($value, 'assets/') => '/'.$value,
                default => '/storage/'.$value,
            },
        );
    }
}
