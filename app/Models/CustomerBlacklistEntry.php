<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Database\Factories\CustomerBlacklistEntryFactory;
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
 * @property string $phone_hash
 * @property string $phone_encrypted
 * @property string|null $reason
 * @property string|null $notes
 * @property int|null $added_by_user_id
 * @property Carbon|null $created_at
 */
#[Fillable(['business_id', 'phone_hash', 'phone_encrypted', 'reason', 'notes', 'added_by_user_id'])]
#[Hidden(['phone_encrypted'])]
#[ScopedBy([BusinessScope::class])]
class CustomerBlacklistEntry extends Model
{
    /** @use HasFactory<CustomerBlacklistEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'customer_blacklist_entries';

    protected function casts(): array
    {
        return [
            'phone_encrypted' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
}
