<?php

namespace App\Jobs\Shopify;

use App\Models\Customer;
use App\Models\CustomerBlacklistEntry;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shopify `customers/redact` — erase the personal data this app holds for one
 * customer of one shop.
 *
 * Shopify sends this 10 days after a merchant requests erasure, or 6 months
 * after the customer's last order. The orders themselves are not deleted:
 * doing so would corrupt the append-only commission ledger and the daily
 * stats that reference them. Instead the PII columns are nulled in place,
 * which is what erasure requires — the remaining row is no longer personal
 * data.
 *
 * The phone hash is cleared alongside the phone. It is a plain SHA-256 of a
 * phone number, so leaving it behind would keep the record linkable to an
 * individual by anyone able to enumerate phone numbers.
 *
 * Docs: https://shopify.dev/docs/apps/build/privacy-law-compliance
 */
class HandleCustomerRedactJob implements ShouldQueue
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
            Log::warning('Shopify customers/redact for an unknown shop; nothing to erase.', [
                'shop_domain' => $this->payload['shop_domain'] ?? null,
            ]);

            return;
        }

        $phone = $this->payload['customer']['phone'] ?? null;

        if (! is_string($phone) || $phone === '') {
            Log::warning('Shopify customers/redact carried no phone number; cannot match a customer.', [
                'store_id' => $this->store->id,
                'shopify_customer_id' => $this->payload['customer']['id'] ?? null,
            ]);

            return;
        }

        $phoneHash = hash('sha256', $phone);

        $redactedOrders = 0;
        $redactedCustomers = 0;
        $redactedBlacklistEntries = 0;

        DB::transaction(function () use ($phoneHash, &$redactedOrders, &$redactedCustomers, &$redactedBlacklistEntries): void {
            $redactedOrders = Order::withoutGlobalScopes()
                ->where('store_id', $this->store->id)
                ->where('customer_phone_hash', $phoneHash)
                ->update([
                    'customer_name' => null,
                    'customer_phone' => null,
                    'customer_address' => null,
                    'customer_phone_hash' => null,
                    'customer_ip_address' => null,
                ]);

            // The customer record is business-scoped rather than store-scoped,
            // so only erase it once this phone has no orders left anywhere in
            // the business — another store of the same tenant may still hold a
            // live relationship with them.
            $remaining = Order::withoutGlobalScopes()
                ->where('business_id', $this->store->business_id)
                ->where('customer_phone_hash', $phoneHash)
                ->exists();

            if (! $remaining) {
                $redactedCustomers = Customer::withoutGlobalScopes()
                    ->where('business_id', $this->store->business_id)
                    ->where('phone_hash', $phoneHash)
                    ->update([
                        'name' => null,
                        'phone' => null,
                        'address' => null,
                        'phone_hash' => null,
                    ]);
            }

            // Blacklist entries are kept rather than deleted: erasing them
            // would let a customer clear a fraud/refusal record by asking the
            // merchant for redaction. Only the reversible copy of the phone is
            // dropped — the one-way hash stays, so the block keeps working
            // without the app storing a recoverable phone number.
            $redactedBlacklistEntries = CustomerBlacklistEntry::withoutGlobalScopes()
                ->where('business_id', $this->store->business_id)
                ->where('phone_hash', $phoneHash)
                ->update([
                    'phone_encrypted' => null,
                    'notes' => null,
                ]);
        });

        Log::info('Shopify customers/redact completed.', [
            'store_id' => $this->store->id,
            'business_id' => $this->store->business_id,
            'redacted_orders' => $redactedOrders,
            'redacted_customers' => $redactedCustomers,
            'redacted_blacklist_entries' => $redactedBlacklistEntries,
        ]);
    }
}
