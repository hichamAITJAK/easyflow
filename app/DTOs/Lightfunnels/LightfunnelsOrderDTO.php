<?php

namespace App\DTOs\Lightfunnels;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a Lightfunnels Order.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class LightfunnelsOrderDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public ?int $_id,
        public string $reference,
        public ?string $fulfillmentStatus,
        public ?string $financialStatus,
        public float $total,
        public float $subtotal,
        public float $discountValue,
        public float $shippingCost,
        public ?string $customerId,
        public ?string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public ?LightfunnelsAddressDTO $shippingAddress,
        /** @var LightfunnelsLineItemDTO[] */
        public array $lineItems,
        public ?string $notes,
        public bool $test,
        public ?string $cancelledAt,
        /**
         * ISO 8601 — LightfunnelsOrderService requests this field with an
         * explicit `format()` argument on Lightfunnels' TimeStamp scalar,
         * since the scalar's raw representation isn't otherwise documented.
         */
        public ?string $createdAt,
    ) {}

    /**
     * @param  array{node?: array<string, mixed>, order?: array<string, mixed>, id?: mixed, _id?: mixed, name?: mixed, fulfillment_status?: ?string, financial_status?: ?string, total?: mixed, subtotal?: mixed, discount_value?: mixed, shipping?: mixed, customer?: array{id?: ?string, full_name?: ?string, email?: ?string, phone?: ?string}, email?: ?string, phone?: ?string, shipping_address?: array<string, mixed>, items?: array<int, array<string, mixed>>, notes?: ?string, test?: mixed, cancelled_at?: ?string, created_at?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        $order = $data['node'] ?? $data['order'] ?? $data;

        $lineItems = array_map(
            fn (array $item) => LightfunnelsLineItemDTO::fromArray($item),
            $order['items'] ?? []
        );

        $shippingAddress = ! empty($order['shipping_address'])
            ? LightfunnelsAddressDTO::fromArray($order['shipping_address'])
            : null;

        return new self(
            id: (string) ($order['id'] ?? ''),
            _id: isset($order['_id']) ? (int) $order['_id'] : null,
            reference: (string) ($order['name'] ?? $order['id'] ?? ''),
            fulfillmentStatus: $order['fulfillment_status'] ?? null,
            financialStatus: $order['financial_status'] ?? null,
            total: (float) ($order['total'] ?? 0),
            subtotal: (float) ($order['subtotal'] ?? 0),
            discountValue: (float) ($order['discount_value'] ?? 0),
            shippingCost: (float) ($order['shipping'] ?? 0),
            customerId: $order['customer']['id'] ?? null,
            customerName: $order['customer']['full_name'] ?? null,
            customerEmail: $order['customer']['email'] ?? $order['email'] ?? null,
            customerPhone: $order['customer']['phone'] ?? $order['phone'] ?? null,
            shippingAddress: $shippingAddress,
            lineItems: $lineItems,
            notes: $order['notes'] ?? null,
            test: (bool) ($order['test'] ?? false),
            cancelledAt: $order['cancelled_at'] ?? null,
            createdAt: $order['created_at'] ?? null,
        );
    }

    /**
     * @param  array{orders?: array{edges?: array<int, array{node?: array<string, mixed>}>}}  $data
     * @return LightfunnelsOrderDTO[]
     */
    public static function fromList(array $data): array
    {
        $items = [];
        foreach ($data['orders']['edges'] ?? [] as $edge) {
            if (isset($edge['node'])) {
                $items[] = $edge['node'];
            }
        }

        return array_map(
            fn (array $order) => self::fromArray($order),
            $items
        );
    }

    /**
     * The Lightfunnels product ids referenced by this order's line items —
     * used to determine which of the account's stores this order belongs
     * to, since Lightfunnels orders carry no direct store reference via the
     * GraphQL API (only webhook payloads do).
     *
     * @return string[]
     */
    public function productIds(): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (LightfunnelsLineItemDTO $item) => $item->productId, $this->lineItems)
        )));
    }

    /**
     * @return array{id: string, _id: ?int, reference: string, fulfillment_status: ?string, financial_status: ?string, total: float, subtotal: float, discount_value: float, delivery_cost: float, customer_id: ?string, customer_name: ?string, customer_email: ?string, customer_phone: ?string, shipping_address: ?array{first_name: ?string, last_name: ?string, first_line: ?string, second_line: ?string, city: ?string, province: ?string, country: ?string, zip: ?string, phone: ?string}, line_items: array<int, array<string, mixed>>, note: ?string, test: bool, cancelled_at: ?string, created_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            '_id' => $this->_id,
            'reference' => $this->reference,
            'fulfillment_status' => $this->fulfillmentStatus,
            'financial_status' => $this->financialStatus,
            'total' => $this->total,
            'subtotal' => $this->subtotal,
            'discount_value' => $this->discountValue,
            'delivery_cost' => $this->shippingCost,
            'customer_id' => $this->customerId,
            'customer_name' => $this->customerName,
            'customer_email' => $this->customerEmail,
            'customer_phone' => $this->customerPhone,
            'shipping_address' => $this->shippingAddress?->toArray(),
            'line_items' => $this->consolidatedLineItems(),
            'note' => $this->notes,
            'test' => $this->test,
            'cancelled_at' => $this->cancelledAt,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Group Lightfunnels' one-row-per-unit line items into a quantity per
     * distinct (product, variant, price) combination, matching the
     * quantity-bearing line item shape OrderSyncService expects.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consolidatedLineItems(): array
    {
        $grouped = [];

        foreach ($this->lineItems as $item) {
            $key = implode('|', [$item->productId ?? '', $item->variantTitle ?? '', $item->price]);

            if (! isset($grouped[$key])) {
                $grouped[$key] = $item->toArray();

                continue;
            }

            $grouped[$key]['quantity']++;
        }

        return array_values($grouped);
    }
}
