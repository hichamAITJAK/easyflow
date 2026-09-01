<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hour-of-day confirmation buckets feeding the dashboard's "Best hour to
 * confirm" chart. agent_id NULL = the business-wide bucket. attempts_count
 * counts agent contact outcomes that hour; confirmed_count the successes —
 * the chart derives rate = confirmed ÷ attempts. Kept out of
 * daily_stats_summary deliberately: that table's grain is per-day.
 *
 * @property int $id
 * @property int $business_id
 * @property int|null $agent_id
 * @property Carbon $stat_date
 * @property int $hour
 * @property int $attempts_count
 * @property int $confirmed_count
 * @property Carbon|null $created_at
 */
#[Fillable(['business_id', 'agent_id', 'stat_date', 'hour', 'attempts_count', 'confirmed_count'])]
#[ScopedBy([BusinessScope::class])]
class HourlyConfirmationStat extends Model
{
    public $timestamps = false;

    protected $table = 'hourly_confirmation_stats';

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'hour' => 'integer',
            'attempts_count' => 'integer',
            'confirmed_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
