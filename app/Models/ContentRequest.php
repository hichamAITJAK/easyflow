<?php

namespace App\Models;

use App\Enums\ContentRequestStatus;
use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One workload for one editor. A request to two editors is two rows,
 * each with its own copy of the items, that live and die independently.
 *
 * @property int $id
 * @property int $business_id
 * @property int $creative_product_id
 * @property int|null $editor_id
 * @property string $origin
 * @property ContentRequestStatus $status
 * @property int $rev
 * @property string|null $admin_note
 * @property string|null $drive_url
 * @property string|null $editor_note
 * @property array<int, string> $direction_points
 * @property Carbon|null $returned_at
 * @property Carbon|null $edits_requested_at
 * @property Carbon|null $validated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['business_id', 'creative_product_id', 'editor_id', 'origin', 'status', 'rev', 'admin_note', 'drive_url', 'editor_note', 'direction_points', 'returned_at', 'edits_requested_at', 'validated_at'])]
#[ScopedBy([BusinessScope::class])]
class ContentRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ContentRequestStatus::class,
            'direction_points' => 'array',
            'returned_at' => 'datetime',
            'edits_requested_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreativeProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CreativeProduct::class, 'creative_product_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    /** @return HasMany<ContentRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ContentRequestItem::class)->orderBy('id');
    }

    /** The pay row written at validation. @return HasOne<CommissionLedgerEntry, $this> */
    public function commission(): HasOne
    {
        return $this->hasOne(CommissionLedgerEntry::class);
    }

    /** "Videos ×4 + Statics ×3", from the loaded items. */
    public function label(): string
    {
        return $this->items
            ->map(fn (ContentRequestItem $item) => ($item->type === 'video' ? 'Videos' : 'Statics').' ×'.$item->count)
            ->implode(' + ');
    }

    public function totalCount(): int
    {
        return (int) $this->items->sum('count');
    }
}
