<?php

namespace App\DTOs\Storeep;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a Storeep order (from listOrders()).
 *
 * Storeep has no per-order endpoint — `/orders` is the only way to read
 * orders, so every order (including one arriving by webhook) is resolved
 * out of that list.
 *
 * Customer identity lives entirely on the order's addresses; there is no
 * customer object and no customer id, so there is nothing to backfill from
 * a customer endpoint the way YouCan requires.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class StoreepOrderDTO implements Arrayable
{
    public function __construct(
        public string $id,
        public ?string $reference,
        public ?string $status,
        public float $subtotal,
        public float $discount,
        public float $total,
        public float $deliveryCost,
        public ?float $vat,
        public ?string $currency,
        public ?string $market,
        public ?string $shippingMethod,
        public ?string $paymentMethod,
        public ?StoreepAddressDTO $shippingAddress,
        /** @var StoreepLineItemDTO[] */
        public array $lineItems,
        public bool $isAbandoned,
        public bool $isDuplicated,
        public bool $isReturning,
        public bool $isPaid,
        public bool $isFulfilled,
        public bool $isTest,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param  array{
     *     order?: array<string, mixed>,
     *     data?: array<string, mixed>,
     *     id?: string|int,
     *     number?: string|int|null,
     *     status?: ?string,
     *     subtotal?: float|string|null,
     *     discount?: float|string|null,
     *     shipping?: float|string|null,
     *     vat?: float|string|null,
     *     total?: float|string|null,
     *     currency?: ?string,
     *     market?: ?string,
     *     shipping_method?: ?string,
     *     payment_method?: ?string,
     *     is_abandoned?: bool,
     *     is_duplicated?: bool,
     *     is_returning?: bool,
     *     is_paid?: bool,
     *     is_fulfilled?: bool,
     *     is_test?: bool,
     *     created_at?: ?string,
     *     updated_at?: ?string,
     *     items?: array<int, array<string, mixed>>,
     *     addresses?: array<int, array<string, mixed>>,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $order = $data['order'] ?? $data['data'] ?? $data;

        $lineItems = array_map(
            fn (array $item) => StoreepLineItemDTO::fromArray($item),
            $order['items'] ?? []
        );

        return new self(
            id: (string) ($order['id'] ?? ''),
            // `number` is the merchant-facing order number (1042); the id is
            // the API handle. Falls back to the id so a reference is never
            // empty.
            reference: isset($order['number']) ? (string) $order['number'] : ((string) ($order['id'] ?? '') ?: null),
            status: $order['status'] ?? null,
            subtotal: (float) ($order['subtotal'] ?? 0),
            discount: (float) ($order['discount'] ?? 0),
            total: (float) ($order['total'] ?? 0),
            deliveryCost: (float) ($order['shipping'] ?? 0),
            vat: isset($order['vat']) ? (float) $order['vat'] : null,
            currency: $order['currency'] ?? null,
            market: $order['market'] ?? null,
            shippingMethod: $order['shipping_method'] ?? null,
            paymentMethod: $order['payment_method'] ?? null,
            shippingAddress: StoreepAddressDTO::shippingFromList($order['addresses'] ?? []),
            lineItems: $lineItems,
            isAbandoned: (bool) ($order['is_abandoned'] ?? false),
            isDuplicated: (bool) ($order['is_duplicated'] ?? false),
            isReturning: (bool) ($order['is_returning'] ?? false),
            isPaid: (bool) ($order['is_paid'] ?? false),
            isFulfilled: (bool) ($order['is_fulfilled'] ?? false),
            isTest: (bool) ($order['is_test'] ?? false),
            createdAt: $order['created_at'] ?? null,
            updatedAt: $order['updated_at'] ?? null,
        );
    }

    /**
     * @param  array{data?: array<int, array<string, mixed>>, orders?: array<int, array<string, mixed>>}|array<int, array<string, mixed>>  $data
     * @return StoreepOrderDTO[]
     */
    public static function fromList(array $data): array
    {
        $items = $data['data'] ?? $data['orders'] ?? $data;

        return array_map(
            fn (array $order) => self::fromArray($order),
            $items
        );
    }

    /**
     * The name to show for this order's customer.
     *
     * Storeep has no customer object — the shipping address is the only
     * place a name appears.
     */
    public function customerName(): ?string
    {
        $name = $this->shippingAddress?->name();

        return ($name === null || $name === '') ? null : $name;
    }

    /**
     * @return array{
     *     id: string,
     *     reference: ?string,
     *     status: ?string,
     *     subtotal: float,
     *     discount: float,
     *     total: float,
     *     delivery_cost: float,
     *     vat: ?float,
     *     currency: ?string,
     *     market: ?string,
     *     shipping_method: ?string,
     *     payment_method: ?string,
     *     customer_name: ?string,
     *     customer_email: null,
     *     customer_phone: ?string,
     *     shipping_address: ?array<string, mixed>,
     *     line_items: array<int, array<string, mixed>>,
     *     note: ?string,
     *     is_abandoned: bool,
     *     is_duplicated: bool,
     *     is_returning: bool,
     *     is_paid: bool,
     *     is_fulfilled: bool,
     *     is_test: bool,
     *     created_at: ?string,
     *     updated_at: ?string,
     * }
     */
    public function toArray(): array
    {
        $shippingAddress = $this->shippingAddress?->toArray();

        // The order's `market` is a 2-letter country code and the address
        // itself has no country field — so it is applied here, where both
        // are in scope, rather than guessed at inside the address DTO.
        if ($shippingAddress !== null && $this->market !== null) {
            $shippingAddress['country_code'] = $this->market;
        }

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'delivery_cost' => $this->deliveryCost,
            'vat' => $this->vat,
            'currency' => $this->currency,
            'market' => $this->market,
            'shipping_method' => $this->shippingMethod,
            'payment_method' => $this->paymentMethod,
            'customer_name' => $this->customerName(),
            // Storeep exposes no customer email anywhere on the order.
            'customer_email' => null,
            'customer_phone' => $this->shippingAddress?->phone,
            'shipping_address' => $shippingAddress,
            'line_items' => array_map(fn ($item) => $item->toArray(), $this->lineItems),
            'note' => $this->shippingAddress?->note,
            'is_abandoned' => $this->isAbandoned,
            'is_duplicated' => $this->isDuplicated,
            'is_returning' => $this->isReturning,
            'is_paid' => $this->isPaid,
            'is_fulfilled' => $this->isFulfilled,
            'is_test' => $this->isTest,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
