<?php

namespace App\Models;

use App\Enums\BusinessStatus;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $logo
 * @property string|null $ice
 * @property string|null $rc
 * @property string|null $if_number
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $city
 * @property string $slug
 * @property BusinessStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'legal_name', 'logo', 'ice', 'rc', 'if_number', 'phone', 'email', 'address', 'city', 'slug', 'status'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BusinessStatus::class,
        ];
    }

    /**
     * Resolve the logo's public URL from its stored path, mirroring
     * User::avatar() — host-relative rather than Storage::url(), which
     * bakes in APP_URL and 404s whenever that doesn't match the host
     * actually serving the app.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function logo(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : '/storage/'.$value,
        );
    }

    /**
     * Get the users associated with this business.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the stores connected by this business.
     *
     * @return HasMany<Store, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * Get the delivery courier accounts connected by this business.
     *
     * @return HasMany<DeliveryAccount, $this>
     */
    public function deliveryAccounts(): HasMany
    {
        return $this->hasMany(DeliveryAccount::class);
    }

    /**
     * Get the products belonging to this business.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get the orders belonging to this business.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the order items belonging to this business.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the order status events belonging to this business.
     *
     * @return HasMany<OrderStatusEvent, $this>
     */
    public function orderStatusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class);
    }

    /**
     * Get the agent scopes defined for this business.
     *
     * @return HasMany<AgentScope, $this>
     */
    public function agentScopes(): HasMany
    {
        return $this->hasMany(AgentScope::class);
    }

    /**
     * Get the commission rules defined for this business.
     *
     * @return HasMany<CommissionRule, $this>
     */
    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    /**
     * Get the commission ledger entries belonging to this business.
     *
     * @return HasMany<CommissionLedgerEntry, $this>
     */
    public function commissionLedgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class);
    }

    /**
     * Get the blacklisted customer phone entries for this business.
     *
     * @return HasMany<CustomerBlacklistEntry, $this>
     */
    public function customerBlacklistEntries(): HasMany
    {
        return $this->hasMany(CustomerBlacklistEntry::class);
    }

    /**
     * Get the customers for this business.
     *
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Get the daily stats summaries for this business.
     *
     * @return HasMany<DailyStatsSummary, $this>
     */
    public function dailyStatsSummaries(): HasMany
    {
        return $this->hasMany(DailyStatsSummary::class);
    }

    /**
     * Generate a slug from the business name, disambiguated with a numeric
     * suffix on collision since the column carries a unique constraint.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $suffix = 1;

        while (self::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
