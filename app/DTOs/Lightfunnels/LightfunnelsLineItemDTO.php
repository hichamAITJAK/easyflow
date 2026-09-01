<?php

namespace App\DTOs\Lightfunnels;

/**
 * Represents a single item (VariantSnapshot or OrderBumpSnapshot) in a Lightfunnels order.
 *
 * Unlike Shopify/YouCan, Lightfunnels has no `quantity` on a line item —
 * each unit ordered gets its own VariantSnapshot/OrderBumpSnapshot row, so
 * `quantity` here always represents one snapshot. Identical items (same
 * product + variant options) are consolidated into a single quantity when
 * mapped to our own order_items schema — see LightfunnelsOrderDTO::toArray().
 */
readonly class LightfunnelsLineItemDTO
{
    public function __construct(
        public string $id,
        public ?string $type,
        public ?string $productId,
        public string $title,
        public ?string $variantTitle,
        public ?string $sku,
        public float $price,
        public ?string $fulfillmentStatus,
        public ?string $carrier,
        public ?string $trackingNumber,
        public ?string $trackingLink,
    ) {}

    /**
     * @param  array{id?: mixed, __typename?: ?string, product_uid?: ?string, title?: mixed, options?: array<int, array{value?: mixed}>, sku?: ?string, price?: mixed, fulfillment_status?: ?string, carrier?: ?string, tracking_number?: ?string, tracking_link?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        $options = array_map(
            fn (array $option) => (string) ($option['value'] ?? ''),
            $data['options'] ?? []
        );

        return new self(
            id: (string) ($data['id'] ?? ''),
            type: $data['__typename'] ?? null,
            productId: $data['product_uid'] ?? null,
            title: (string) ($data['title'] ?? ''),
            variantTitle: $options !== [] ? implode(' / ', $options) : null,
            sku: $data['sku'] ?? null,
            price: (float) ($data['price'] ?? 0),
            fulfillmentStatus: $data['fulfillment_status'] ?? null,
            carrier: $data['carrier'] ?? null,
            trackingNumber: $data['tracking_number'] ?? null,
            trackingLink: $data['tracking_link'] ?? null,
        );
    }

    /**
     * @return array{id: string, type: ?string, product_id: ?string, title: string, variant_title: ?string, sku: ?string, price: float, quantity: int, fulfillment_status: ?string, carrier: ?string, tracking_number: ?string, tracking_link: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'product_id' => $this->productId,
            'title' => $this->title,
            'variant_title' => $this->variantTitle,
            'sku' => $this->sku,
            'price' => $this->price,
            'quantity' => 1,
            'fulfillment_status' => $this->fulfillmentStatus,
            'carrier' => $this->carrier,
            'tracking_number' => $this->trackingNumber,
            'tracking_link' => $this->trackingLink,
        ];
    }
}
