<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property int|null $business_id
 * @property string $name
 * @property string $email
 * @property string|null $google_id
 * @property string|null $phone
 * @property string|null $password
 * @property UserRole $role
 * @property UserStatus $status
 * @property string|null $avatar
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'name', 'email', 'google_id', 'phone', 'password', 'role', 'status', 'avatar', 'last_login_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    /**
     * Get the business that owns this user.
     *
     * @return BelongsTo<Business, $this>
     */
    /**
     * A super admin never belongs to a business. Enforced at save time
     * rather than trusted to every caller: the users table cascade-deletes
     * with its business, so a super admin that carries a business_id is
     * destroyed the moment that business is removed — which is exactly
     * the account that must survive it.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->role === UserRole::SUPER_ADMIN) {
                $user->business_id = null;
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the orders assigned to this user as a delivery agent.
     *
     * @return HasMany<Order, $this>
     */
    public function assignedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'assigned_agent_id');
    }

    /**
     * Get the agent scopes restricting this user's visibility.
     *
     * @return HasMany<AgentScope, $this>
     */
    public function agentScopes(): HasMany
    {
        return $this->hasMany(AgentScope::class);
    }

    /**
     * Get this user's registered push notification device tokens — one per
     * device (PRD: "supports multiple devices per user"), so a notification
     * fans out to every device they're logged in on.
     *
     * @return HasMany<DeviceToken, $this>
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Get the commission rules assigned to this user.
     *
     * @return HasMany<CommissionRule, $this>
     */
    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    /**
     * Get the performance targets assigned to this user.
     *
     * @return HasMany<PerformanceTarget, $this>
     */
    public function performanceTargets(): HasMany
    {
        return $this->hasMany(PerformanceTarget::class);
    }

    /**
     * Get the commission ledger entries earned by this user.
     *
     * @return HasMany<CommissionLedgerEntry, $this>
     */
    public function commissionLedgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class);
    }

    /**
     * Get the order status events changed by this user.
     *
     * @return HasMany<OrderStatusEvent, $this>
     */
    public function orderStatusEventsChanged(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class, 'changed_by_user_id');
    }

    /**
     * Resolve the avatar's public URL from its stored path: uploaded files
     * live on the public storage disk, preset avatars under public/assets.
     *
     * Deliberately host-relative (not Storage::disk('public')->url(), which
     * bakes in APP_URL) — APP_URL rarely matches the port/host actually
     * serving the app in local dev, which silently 404s the image while
     * looking like a rendering bug.
     *
     * @return Attribute<string, string|null>
     */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => match (true) {
                $value === null => null,
                str_starts_with($value, 'assets/') => '/'.$value,
                default => '/storage/'.$value,
            },
        );
    }
}
