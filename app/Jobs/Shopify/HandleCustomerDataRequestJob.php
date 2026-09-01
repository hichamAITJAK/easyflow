<?php

namespace App\Jobs\Shopify;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Shopify `customers/data_request` — a merchant has asked, on a customer's
 * behalf, for the personal data this app holds about that customer.
 *
 * Shopify does not collect the data itself: it only relays the request. The
 * app is responsible for getting the data to the merchant within 30 days.
 * This job assembles the record set and logs that a request is outstanding so
 * it can be actioned; it deliberately does not email PII anywhere on its own.
 *
 * Docs: https://shopify.dev/docs/apps/build/privacy-law-compliance
 */
class HandleCustomerDataRequestJob implements ShouldQueue
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
            Log::warning('Shopify customers/data_request for an unknown shop.', [
                'shop_domain' => $this->payload['shop_domain'] ?? null,
            ]);

            return;
        }

        $phoneHashes = $this->customerPhoneHashes();

        $orderIds = $phoneHashes === []
            ? []
            : Order::withoutGlobalScopes()
                ->where('store_id', $this->store->id)
                ->whereIn('customer_phone_hash', $phoneHashes)
                ->pluck('id')
                ->all();

        // Metadata only — per the PRD's security rules, no plaintext client
        // PII may reach the logs. The identifiers here are enough to build the
        // export when the request is fulfilled.
        Log::info('Shopify customers/data_request received; customer data export is due within 30 days.', [
            'store_id' => $this->store->id,
            'business_id' => $this->store->business_id,
            'shopify_customer_id' => $this->payload['customer']['id'] ?? null,
            'shopify_order_ids' => $this->payload['orders_requested'] ?? [],
            'matched_order_ids' => $orderIds,
            'matched_order_count' => count($orderIds),
        ]);
    }

    /**
     * Hash the customer's phone the same way order ingestion does, so the
     * request can be matched without ever decrypting stored PII.
     *
     * @return array<int, string>
     */
    private function customerPhoneHashes(): array
    {
        $phone = $this->payload['customer']['phone'] ?? null;

        if (! is_string($phone) || $phone === '') {
            return [];
        }

        return [hash('sha256', $phone)];
    }
}
