<?php

namespace App\DTOs\WooCommerce;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Represents a WooCommerce order.
 *
 * Two WooCommerce specifics shape this DTO:
 *
 *  1. **Shipping is often blank.** WooCommerce only populates the
 *     `shipping` object when the customer opted to ship to a different
 *     address (or when the store forces it). For most COD checkouts the
 *     delivery address is the BILLING address, and `shipping` comes back
 *     present but entirely empty. Reading shipping alone would produce
 *     orders with no address and no phone, which for a COD business means
 *     an undeliverable order. So both are read, shipping preferred, with a
 *     fall back to billing.
 *
 *  2. **Only billing carries contact details.** `email` exists on billing
 *     alone, and `phone` was not added to shipping until WooCommerce 5.6 —
 *     so the phone number, which is the single most important field in a
 *     COD workflow, is read from shipping only if present and otherwise
 *     from billing.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class WooCommerceOrderDTO implements Arrayable
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
        public ?string $paymentMethod,
        public ?string $paymentMethodTitle,
        public ?WooCommerceAddressDTO $billingAddress,
        public ?WooCommerceAddressDTO $shippingAddress,
        /** @var WooCommerceLineItemDTO[] */
        public array $lineItems,
        public ?string $customerNote,
        public bool $isPaid,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param  array{
     *     id?: string|int,
     *     number?: string|int|null,
     *     status?: ?string,
     *     currency?: ?string,
     *     total?: float|string|null,
     *     discount_total?: float|string|null,
     *     shipping_total?: float|string|null,
     *     total_tax?: float|string|null,
     *     payment_method?: ?string,
     *     payment_method_title?: ?string,
     *     date_paid?: ?string,
     *     customer_note?: ?string,
     *     billing?: array<string, mixed>,
     *     shipping?: array<string, mixed>,
     *     line_items?: array<int, array<string, mixed>>,
     *     date_created?: ?string,
     *     date_created_gmt?: ?string,
     *     date_modified?: ?string,
     *     date_modified_gmt?: ?string,
     * }  $order  A WooCommerce order, exactly as the REST API and the
     *             webhook payload both represent it — the two are the same
     *             shape, so neither is unwrapped from an envelope.
     */
    public static function fromArray(array $order): self
    {
        $billing = isset($order['billing'])
            ? WooCommerceAddressDTO::fromArray($order['billing'])
            : null;

        $shipping = isset($order['shipping'])
            ? WooCommerceAddressDTO::fromArray($order['shipping'])
            : null;

        $lineItems = WooCommerceLineItemDTO::fromList($order['line_items'] ?? []);

        $total = (float) ($order['total'] ?? 0);
        $shippingTotal = (float) ($order['shipping_total'] ?? 0);
        $discount = (float) ($order['discount_total'] ?? 0);
        $tax = (float) ($order['total_tax'] ?? 0);

        return new self(
            id: (string) ($order['id'] ?? ''),
            // `number` is the merchant-facing order number, which sequential-
            // order-number plugins commonly override; `id` is the API handle.
            // Falls back to the id so a reference is never empty.
            reference: isset($order['number']) && (string) $order['number'] !== ''
                ? (string) $order['number']
                : ((string) ($order['id'] ?? '') ?: null),
            status: $order['status'] ?? null,
            // WooCommerce reports no order-level subtotal — `total` is the
            // final figure. Deriving it keeps the field meaningful rather
            // than reporting zero: goods = total - shipping - tax + discount.
            subtotal: round($total - $shippingTotal - $tax + $discount, 2),
            discount: $discount,
            total: $total,
            deliveryCost: $shippingTotal,
            vat: $tax,
            currency: $order['currency'] ?? null,
            paymentMethod: $order['payment_method'] ?? null,
            paymentMethodTitle: $order['payment_method_title'] ?? null,
            billingAddress: $billing,
            shippingAddress: $shipping,
            lineItems: $lineItems,
            customerNote: self::nullIfBlank($order['customer_note'] ?? null),
            // `date_paid` is null until payment completes — for a COD order
            // that is normally the moment cash is collected, so this stays
            // false through the whole delivery cycle.
            isPaid: ! empty($order['date_paid']),
            // The GMT variants are unambiguous; the non-GMT ones are in store
            // time with no offset attached, so they are only a fallback.
            createdAt: $order['date_created_gmt'] ?? $order['date_created'] ?? null,
            updatedAt: $order['date_modified_gmt'] ?? $order['date_modified'] ?? null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @return WooCommerceOrderDTO[]
     */
    public static function fromList(array $orders): array
    {
        return array_map(fn (array $order) => self::fromArray($order), $orders);
    }

    /**
     * The address to deliver to.
     *
     * Shipping wins when the customer actually supplied one; otherwise the
     * billing address is the delivery address, which is the common case for
     * a COD checkout with no separate shipping step.
     */
    public function deliveryAddress(): ?WooCommerceAddressDTO
    {
        if ($this->shippingAddress !== null && ! $this->shippingAddress->isEmpty()) {
            return $this->shippingAddress;
        }

        return $this->billingAddress;
    }

    /**
     * The name to show for this order's customer.
     */
    public function customerName(): ?string
    {
        return $this->deliveryAddress()?->name() ?? $this->billingAddress?->name();
    }

    /**
     * The phone number to call to confirm this order.
     *
     * This is the field a COD call-center runs on, so it is resolved
     * deliberately: shipping's phone only exists on WooCommerce 5.6+ and is
     * frequently blank even there, so billing is the reliable source.
     *
     * Note this cannot be written as `deliveryAddress()?->phone ??
     * $billing?->phone`: when the delivery address IS the billing address,
     * that expression re-reads the same null and the fallback never fires.
     */
    public function customerPhone(): ?string
    {
        foreach ([$this->shippingAddress, $this->billingAddress] as $address) {
            if ($address?->phone !== null) {
                return $address->phone;
            }
        }

        return null;
    }

    /**
     * WooCommerce exposes the customer's email on the billing address only —
     * the shipping address has no email field at all.
     */
    public function customerEmail(): ?string
    {
        return $this->billingAddress?->email;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
     *     payment_method: ?string,
     *     payment_method_title: ?string,
     *     customer_name: ?string,
     *     customer_email: ?string,
     *     customer_phone: ?string,
     *     shipping_address: ?array<string, mixed>,
     *     billing_address: ?array<string, mixed>,
     *     line_items: array<int, array<string, mixed>>,
     *     note: ?string,
     *     is_paid: bool,
     *     created_at: ?string,
     *     updated_at: ?string,
     * }
     */
    public function toArray(): array
    {
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
            'payment_method' => $this->paymentMethod,
            'payment_method_title' => $this->paymentMethodTitle,
            'customer_name' => $this->customerName(),
            'customer_email' => $this->customerEmail(),
            'customer_phone' => $this->customerPhone(),
            // OrderSyncService reads the delivery address out of
            // `shipping_address`, so the resolved one goes there — not
            // WooCommerce's raw (often blank) shipping object.
            'shipping_address' => $this->deliveryAddress()?->toArray(),
            'billing_address' => $this->billingAddress?->toArray(),
            'line_items' => array_map(fn ($item) => $item->toArray(), $this->lineItems),
            'note' => $this->customerNote,
            'is_paid' => $this->isPaid,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
