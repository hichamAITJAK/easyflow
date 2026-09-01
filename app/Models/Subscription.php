<?php

namespace App\Models;

use App\Enums\SubscriptionPaymentMethod;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int|null $plan_id
 * @property SubscriptionStatus $status
 * @property string $reference_code
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property array<string, int>|null $limits
 * @property numeric-string|null $paid_amount
 * @property SubscriptionPaymentMethod|null $payment_method
 * @property string|null $payment_reference
 * @property string|null $receipt_path
 * @property Carbon|null $submitted_at
 * @property int|null $activated_by
 * @property string|null $rejection_reason
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Business $business
 * @property-read Plan|null $plan
 * @property-read User|null $activatedBy
 */
#[Fillable([
    'business_id', 'plan_id', 'status', 'reference_code', 'starts_at', 'ends_at',
    'limits', 'paid_amount', 'payment_method', 'payment_reference', 'receipt_path',
    'submitted_at', 'activated_by', 'rejection_reason', 'notes',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'payment_method' => SubscriptionPaymentMethod::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'submitted_at' => 'datetime',
            'limits' => 'array',
            'paid_amount' => 'decimal:2',
        ];
    }

    /**
     * Get the business this subscription belongs to.
     *
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the plan this subscription was sold under (null for trials).
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the super admin who activated this subscription.
     *
     * @return BelongsTo<User, $this>
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * Scope to subscriptions that may currently grant tenant access:
     * a running trial, or a paid subscription within its grace window.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->where('status', SubscriptionStatus::TRIALING)
                ->where('ends_at', '>', now());
        })->orWhere(function (Builder $query) {
            // plan_id guard: expired trials must not inherit the paid grace
            // window — trial ends hard at ends_at.
            $query->whereNotNull('plan_id')
                ->whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::EXPIRED])
                ->where('ends_at', '>', now()->subDays((int) config('subscription.grace_days')));
        });
    }

    /**
     * Whether this subscription currently grants access to the tenant app.
     */
    public function grantsAccess(): bool
    {
        if ($this->ends_at === null) {
            return false;
        }

        return match ($this->status) {
            SubscriptionStatus::TRIALING => $this->ends_at->isFuture(),
            // Paid subscriptions keep access through the grace window so a
            // slow bank transfer never cuts a paying business off mid-day.
            // Expired trials (plan_id null) get no grace — hard stop.
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::EXPIRED => $this->plan_id !== null && $this->graceEndsAt()->isFuture(),
            default => false,
        };
    }

    /**
     * Whether this subscription is past ends_at but inside the grace window.
     */
    public function isInGracePeriod(): bool
    {
        return $this->plan_id !== null
            && $this->status !== SubscriptionStatus::TRIALING
            && $this->ends_at !== null
            && $this->ends_at->isPast()
            && $this->graceEndsAt()->isFuture();
    }

    /**
     * When access is actually cut for a paid subscription.
     */
    public function graceEndsAt(): CarbonInterface
    {
        return $this->ends_at->addDays((int) config('subscription.grace_days'));
    }

    /**
     * Whole days remaining before ends_at (0 when past due). Floored, so
     * "3 days remaining" means at least 3 full days — this is what the
     * reminder thresholds compare against.
     */
    public function daysRemaining(): int
    {
        if ($this->ends_at === null || $this->ends_at->isPast()) {
            return 0;
        }

        return (int) now()->diffInDays($this->ends_at, true);
    }

    /**
     * Get a feature ceiling from the activation snapshot, falling back to
     * the plan's current limits. Null means unlimited.
     */
    public function limit(string $key): ?int
    {
        return $this->limits[$key] ?? $this->plan?->limits[$key] ?? null;
    }

    /**
     * Generate the next human-readable transfer reference, e.g. "SUB-2031".
     */
    public static function nextReferenceCode(): string
    {
        $next = (int) (self::query()->max('id') ?? 0) + 1;

        return 'SUB-'.str_pad((string) ($next + 1000), 4, '0', STR_PAD_LEFT);
    }
}
