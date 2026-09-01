<?php

namespace App\Jobs\Shopify;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shopify `shop/redact` — fires 48 hours after a shop uninstalls the app.
 * Erase every piece of personal data held for that shop.
 *
 * As with customers/redact, order rows are kept but stripped of PII: the
 * commission ledger is append-only and the daily stats summary references
 * these orders, so deleting them would corrupt the tenant's own financial
 * history for a shop they may later reconnect. What must not survive is the
 * personal data, and the store's API credentials.
 *
 * Docs: https://shopify.dev/docs/apps/build/privacy-law-compliance
 */
class HandleShopRedactJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly ?Store $store,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        if (! $this->store instanceof Store) {
            // Expected whenever the merchant (or this app) already removed the
            // store between uninstall and the 48-hour redaction window.
            Log::info('Shopify shop/redact for a store that no longer exists; nothing to erase.', [
                'shop_domain' => $this->payload['shop_domain'] ?? null,
            ]);

            return;
        }

        $redactedOrders = 0;
        $redactedCustomers = 0;

        DB::transaction(function () use (&$redactedOrders, &$redactedCustomers): void {
            $redactedOrders = Order::withoutGlobalScopes()
                ->where('store_id', $this->store->id)
                ->update([
                    'customer_name' => null,
                    'customer_phone' => null,
                    'customer_address' => null,
                    'customer_phone_hash' => null,
                    'customer_ip_address' => null,
                ]);

            // Customers are business-scoped and may have been built from more
            // than one store, so only erase those with no orders left in any
            // other store belonging to this business.
            $redactedCustomers = Customer::withoutGlobalScopes()
                ->where('business_id', $this->store->business_id)
                ->whereNotNull('phone_hash')
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('orders')
                        ->whereColumn('orders.customer_phone_hash', 'customers.phone_hash')
                        ->where('orders.business_id', $this->store->business_id);
                })
                ->update([
                    'name' => null,
                    'phone' => null,
                    'address' => null,
                    'phone_hash' => null,
                ]);

            // Credentials are not personal data, but they are the shop's
            // secrets and must not outlive the relationship.
            $this->store->forceFill([
                'api_credentials' => null,
                'webhook_secret' => null,
            ])->save();
        });

        Log::info('Shopify shop/redact completed.', [
            'store_id' => $this->store->id,
            'business_id' => $this->store->business_id,
            'redacted_orders' => $redactedOrders,
            'redacted_customers' => $redactedCustomers,
        ]);
    }
}
