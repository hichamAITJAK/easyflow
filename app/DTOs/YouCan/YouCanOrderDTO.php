<?php

namespace App\DTOs\YouCan;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a YouCan order (from listOrders() / getOrder()).
 *
 * Customer fields are only populated when the order was fetched with
 * `include=customer` (see YouCanOrderService::getOrderWithCustomer()) —
 * otherwise they fall back to null.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class YouCanOrderDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public string $reference,
        public ?string $status,
        public ?string $paymentStatus,
        public ?string $shippingStatus,
        public ?string $confirmationStatus,
        public float $total,
        public float $deliveryCost,
        public ?string $currency,
        public ?string $customerId,
        public ?string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public ?YouCanAddressDTO $shippingAddress,
        /** @var YouCanLineItemDTO[] */
        public array $lineItems,
        public ?string $note,
        /**
         * YouCan's API sends this as a Unix epoch integer (e.g.
         * 1552497987), not an ISO 8601 string — kept as int|string here
         * (rather than coercing to string) so OrderedAtParser can tell the
         * two formats apart downstream.
         */
        public int|string|null $createdAt,
        public int|string|null $updatedAt,
    ) {}

    /**
     * @param  array{
     *     order?: array<string, mixed>,
     *     id?: string|int,
     *     ref?: string|int,
     *     reference?: string|int,
     *     order_number?: string|int,
     *     status_object?: array{name?: ?string},
     *     status_new?: ?string,
     *     payment_status_new?: ?string,
     *     payment?: array{status_text?: ?string},
     *     shipping_status?: ?string,
     *     shipping?: array{status_text?: ?string, price?: float|string},
     *     confirmation_status?: ?string,
     *     total?: float|string,
     *     total_price?: float|string,
     *     delivery_cost?: float|string,
     *     shipping_cost?: float|string,
     *     currency?: ?string,
     *     customer_name?: ?string,
     *     customer_email?: ?string,
     *     customer_phone?: ?string,
     *     customer?: array{
     *         id?: string|int,
     *         first_name?: ?string,
     *         last_name?: ?string,
     *         full_name?: ?string,
     *         email?: ?string,
     *         phone?: ?string,
     *         city?: ?string,
     *         region?: ?string,
     *         country?: ?string,
     *         address?: array<int, array<string, mixed>>,
     *     },
     *     shipping_address?: array<string, mixed>,
     *     variants?: array<int, array<string, mixed>>,
     *     items?: array<int, array<string, mixed>>,
     *     line_items?: array<int, array<string, mixed>>,
     *     notes?: ?string,
     *     note?: ?string,
     *     created_at?: int|string|null,
     *     updated_at?: int|string|null,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $order = $data['order'] ?? $data;

        $lineItems = array_map(
            fn (array $item) => YouCanLineItemDTO::fromArray($item),
            $order['variants'] ?? $order['items'] ?? $order['line_items'] ?? []
        );

        $customer = $order['customer'] ?? null;

        $shippingAddress = null;
        if (! empty($order['shipping_address'])) {
            $shippingAddress = YouCanAddressDTO::fromArray($order['shipping_address']);
        } elseif (! empty($customer['address'][0])) {
            $shippingAddress = YouCanAddressDTO::fromArray($customer['address'][0]);
        } elseif (! empty($customer['city']) || ! empty($customer['region']) || ! empty($customer['country'])) {
            // YouCan's `/orders` include=customer response nests the
            // customer's saved address entries under `address` (often
            // empty), but carries city/region/country as flat fields
            // directly on the customer object instead — a COD order may
            // have no street-level address at all, just a city.
            $shippingAddress = YouCanAddressDTO::fromArray([
                'first_name' => $customer['first_name'] ?? null,
                'last_name' => $customer['last_name'] ?? null,
                'city' => $customer['city'] ?? null,
                'region' => $customer['region'] ?? null,
                'country_code' => $customer['country'] ?? null,
                'phone' => $customer['phone'] ?? null,
            ]);
        }

        return new self(
            id: (string) ($order['id'] ?? ''),
            reference: (string) ($order['ref'] ?? $order['reference'] ?? $order['order_number'] ?? $order['id'] ?? ''),
            status: $order['status_object']['name'] ?? $order['status_new'] ?? null,
            paymentStatus: $order['payment_status_new'] ?? $order['payment']['status_text'] ?? null,
            shippingStatus: $order['shipping_status'] ?? $order['shipping']['status_text'] ?? null,
            confirmationStatus: $order['confirmation_status'] ?? null,
            total: (float) ($order['total'] ?? $order['total_price'] ?? 0),
            deliveryCost: (float) ($order['shipping']['price'] ?? $order['delivery_cost'] ?? $order['shipping_cost'] ?? 0),
            currency: $order['currency'] ?? null,
            customerId: isset($customer['id']) ? (string) $customer['id'] : null,
            customerName: $order['customer_name'] ?? $customer['full_name'] ?? null,
            customerEmail: $order['customer_email'] ?? $customer['email'] ?? null,
            customerPhone: $order['customer_phone'] ?? $customer['phone'] ?? null,
            shippingAddress: $shippingAddress,
            lineItems: $lineItems,
            note: $order['notes'] ?? $order['note'] ?? null,
            createdAt: $order['created_at'] ?? null,
            updatedAt: $order['updated_at'] ?? null,
        );
    }

    /**
     * @param  array{orders?: array<int, array<string, mixed>>, data?: array<int, array<string, mixed>>}|array<int, array<string, mixed>>  $data
     * @return YouCanOrderDTO[]
     */
    public static function fromList(array $data): array
    {
        $items = $data['orders'] ?? $data['data'] ?? $data;

        return array_map(
            fn (array $order) => self::fromArray($order),
            $items
        );
    }

    /**
     * Return a copy of this order with its shipping address replaced,
     * used to backfill an address from the customer record when the
     * order response itself carried none (YouCan's `/orders` endpoint
     * doesn't reliably nest a nested `customer.address` on include).
     */
    public function withShippingAddress(?YouCanAddressDTO $address): self
    {
        if ($this->shippingAddress !== null || $address === null) {
            return $this;
        }

        return new self(
            id: $this->id,
            reference: $this->reference,
            status: $this->status,
            paymentStatus: $this->paymentStatus,
            shippingStatus: $this->shippingStatus,
            confirmationStatus: $this->confirmationStatus,
            total: $this->total,
            deliveryCost: $this->deliveryCost,
            currency: $this->currency,
            customerId: $this->customerId,
            customerName: $this->customerName,
            customerEmail: $this->customerEmail,
            customerPhone: $this->customerPhone,
            shippingAddress: $address,
            lineItems: $this->lineItems,
            note: $this->note,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     reference: string,
     *     status: ?string,
     *     payment_status: ?string,
     *     shipping_status: ?string,
     *     confirmation_status: ?string,
     *     total: float,
     *     delivery_cost: float,
     *     currency: ?string,
     *     customer_id: ?string,
     *     customer_name: ?string,
     *     customer_email: ?string,
     *     customer_phone: ?string,
     *     shipping_address: ?array<string, mixed>,
     *     line_items: array<int, array<string, mixed>>,
     *     note: ?string,
     *     created_at: int|string|null,
     *     updated_at: int|string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'payment_status' => $this->paymentStatus,
            'shipping_status' => $this->shippingStatus,
            'confirmation_status' => $this->confirmationStatus,
            'total' => $this->total,
            'delivery_cost' => $this->deliveryCost,
            'currency' => $this->currency,
            'customer_id' => $this->customerId,
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
