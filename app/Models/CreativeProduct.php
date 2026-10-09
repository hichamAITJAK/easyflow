<?php

namespace App\Models;

use App\Enums\CreativeProductStatus;
use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A product brief in the Creatives module — identity only (name, kind,
 * links, description, editors, lifecycle). Every workload lives on its
 * content requests, never here; works_count / last_push_at are
 * denormalised from validated requests.
 *
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property string $kind
 * @property array<int, string> $links
 * @property string|null $description
 * @property CreativeProductStatus $status
 * @property int $works_count
 * @property Carbon|null $last_push_at
 */
#[Fillable(['business_id', 'name', 'kind', 'links', 'description', 'status', 'works_count', 'last_push_at'])]
#[ScopedBy([BusinessScope::class])]
class CreativeProduct extends Model
{
    protected function casts(): array
    {
        return [
            'links' => 'array',
            'status' => CreativeProductStatus::class,
            'last_push_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function editors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'creative_product_editor')->withPivot('business_id');
    }

    /** @return HasMany<ContentRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(ContentRequest::class);
    }
}
