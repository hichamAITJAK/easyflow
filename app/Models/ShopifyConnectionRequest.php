<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Shopify connection the merchant has started but Shopify has not yet
 * called back on. Holds the merchant's own app keys until the OAuth
 * callback turns them, plus the access token, into a Store.
 *
 * @property int $id
 * @property int $business_id
 * @property string $shop The myshopify slug, without ".myshopify.com".
 * @property string $client_id
 * @property string $client_secret
 * @property string $state
 */
#[Fillable(['business_id', 'shop', 'client_id', 'client_secret', 'state'])]
#[Hidden(['client_secret', 'state'])]
class ShopifyConnectionRequest extends Model
{
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
