<?php

namespace App\DTOs\Shopify;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a Shopify Order.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class ShopifyOrderDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public string $reference,
        public ?string $status,
        public ?string $paymentStatus,
        public ?string $deliveryStatus,
        public float $total,
        public float $subtotal,
        public float $deliveryCost,
        public ?string $currency,
        public ?string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public ?ShopifyAddressDTO $shippingAddress,
        /** @var ShopifyLineItemDTO[] */
        public array $lineItems,
        public ?string $note,
        /** ISO 8601 — Shopify's GraphQL Order.createdAt is a DateTime scalar. */
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param array{
     *     order?: array<string, mixed>|null,
     *     id?: string|null,
     *     name?: string|null,
     *     reference?: string|null,
     *     displayFulfillmentStatus?: string|null,
     *     displayFinancialStatus?: string|null,
     *     delivery_status?: string|null,
     *     shipping_status?: string|null,
     *     totalPriceSet?: array{shopMoney?: array{amount?: float|string|null, currencyCode?: string|null}|null}|null,
     *     total?: float|string|null,
     *     subtotalPriceSet?: array{shopMoney?: array{amount?: float|string|null}|null}|null,
     *     subtotal?: float|string|null,
     *     totalShippingPriceSet?: array{shopMoney?: array{amount?: float|string|null}|null}|null,
     *     delivery_cost?: float|string|null,
     *     shippingCost?: float|string|null,
     *     currency?: string|null,
     *     customer?: array{firstName?: string|null, lastName?: string|null, email?: string|null, phone?: string|null}|null,
     *     shippingAddress?: array<string, mixed>|null,
     *     lineItems?: array{edges?: array<int, array{node?: array<string, mixed>}>}|null,
     *     line_items?: array<int, array<string, mixed>>|null,
     *     items?: array<int, array<string, mixed>>|null,
     *     note?: string|null,
     *     createdAt?: string|null,
     *     updatedAt?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $order = $data['order'] ?? $data;

        // Extract GQL edges if present
        $rawItems = [];
        if (isset($order['lineItems']['edges'])) {
            foreach ($order['lineItems']['edges'] as $edge) {
                if (isset($edge['node'])) {
                    $rawItems[] = $edge['node'];
                }
            }
        } else {
            $rawItems = $order['line_items'] ?? $order['items'] ?? [];
        }

        $lineItems = array_map(
            fn (array $item) => ShopifyLineItemDTO::fromArray($item),
            $rawItems
        );

        $shippingAddress = null;
        if (! empty($order['shippingAddress'])) {
            $shippingAddress = ShopifyAddressDTO::fromArray($order['shippingAddress']);
        }

        $custFirstName = $order['customer']['firstName'] ?? '';
        $custLastName = $order['customer']['lastName'] ?? '';
        $custFullName = trim("{$custFirstName} {$custLastName}") ?: null;

        return new self(
            id: (string) ($order['id'] ?? ''),
            reference: (string) ($order['name'] ?? $order['reference'] ?? $order['id'] ?? ''),
            status: $order['displayFulfillmentStatus'] ?? null,
            paymentStatus: $order['displayFinancialStatus'] ?? null,
            deliveryStatus: $order['delivery_status'] ?? $order['shipping_status'] ?? null,
            total: (float) ($order['totalPriceSet']['shopMoney']['amount'] ?? ($order['total'] ?? 0)),
            subtotal: (float) ($order['subtotalPriceSet']['shopMoney']['amount'] ?? ($order['subtotal'] ?? 0)),
            deliveryCost: (float) ($order['totalShippingPriceSet']['shopMoney']['amount'] ?? ($order['delivery_cost'] ?? $order['shippingCost'] ?? 0)),
            currency: $order['totalPriceSet']['shopMoney']['currencyCode'] ?? ($order['currency'] ?? null),
            customerName: $custFullName,
            customerEmail: $order['customer']['email'] ?? null,
            customerPhone: $order['customer']['phone'] ?? null,
            shippingAddress: $shippingAddress,
            lineItems: $lineItems,
            note: $order['note'] ?? null,
            createdAt: $order['createdAt'] ?? null,
            updatedAt: $order['updatedAt'] ?? null,
        );
    }

    /**
     * @param array{
     *     orders?: array{edges?: array<int, array{node?: array<string, mixed>}>}|array<int, array<string, mixed>>|null,
     *     data?: array<int, array<string, mixed>>|null,
     * } $data
     * @return ShopifyOrderDTO[]
     */
    public static function fromList(array $data): array
    {
        $items = [];
        if (isset($data['orders']['edges'])) {
            foreach ($data['orders']['edges'] as $edge) {
                if (isset($edge['node'])) {
                    $items[] = $edge['node'];
                }
            }
        } else {
            $items = $data['orders'] ?? $data['data'] ?? $data;
        }

        return array_map(
            fn (array $order) => self::fromArray($order),
            $items
        );
    }

    /**
     * @return array{
     *     id: string,
     *     reference: string,
     *     status: string|null,
     *     payment_status: string|null,
     *     delivery_status: string|null,
     *     total: float,
     *     subtotal: float,
     *     delivery_cost: float,
     *     currency: string|null,
     *     customer_name: string|null,
     *     customer_email: string|null,
     *     customer_phone: string|null,
     *     shipping_address: array<string, mixed>|null,
     *     line_items: array<int, array<string, mixed>>,
     *     note: string|null,
     *     created_at: string|null,
     *     updated_at: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'payment_status' => $this->paymentStatus,
            'delivery_status' => $this->deliveryStatus,
            'total' => $this->total,
            'subtotal' => $this->subtotal,
            'delivery_cost' => $this->deliveryCost,
            'currency' => $this->currency,
            'customer_name' => $this->customerName,
            'customer_email' => $this->customerEmail,
            'customer_phone' => $this->customerPhone,
            'shipping_address' => $this->shippingAddress?->toArray(),
            'line_items' => array_map(fn ($i) => $i->toArray(), $this->lineItems),
            'note' => $this->note,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
