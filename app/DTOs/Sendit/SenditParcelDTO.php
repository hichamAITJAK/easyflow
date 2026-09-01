<?php

namespace App\DTOs\Sendit;

/**
 * Represents a Sendit parcel ("colis"), from listParcels() / getParcel() /
 * createParcel() / updateParcel(). Detail-only fields (address, allow_open,
 * allow_try, labelUrl) are null when built from the list endpoint.
 *
 * trackingNumber/deliveryCost/returnedCost/refusedCost follow the same
 * naming as every other courier's parcel-result DTO (see
 * OzonExpressParcelDTO), so OrderService::createShipment() can read
 * $parcel->trackingNumber regardless of which courier actually answered —
 * Sendit has no return/refuse pricing concept, so those two are always
 * null here.
 */
readonly class SenditParcelDTO
{
    public function __construct(
        public string $trackingNumber,
        public ?string $name,
        public ?string $phone,
        public ?string $address,
        public ?float $deliveryCost,
        public ?float $amount,
        public ?string $status,
        public ?string $statusReturn,
        public ?string $comment,
        public ?string $reference,
        public ?bool $allowOpen,
        public ?bool $allowTry,
        public ?bool $optionExchange,
        public ?SenditDistrictDTO $district,
        /** @var SenditParcelProductDTO[] */
        public array $products,
        public ?string $labelUrl,
        public ?string $lastActionAt,
        public ?float $returnedCost = null,
        public ?float $refusedCost = null,
    ) {}

    /**
     * @param  array{code?: string, name?: ?string, phone?: ?string, address?: ?string, fee?: string|float, amount?: string|float, status?: ?string, status_return?: ?string, comment?: ?string, reference?: ?string, allow_open?: bool|int, allow_try?: bool|int, option_exchange?: bool|int, district?: array<string, mixed>, products?: array<int, array<string, mixed>>, labelUrl?: ?string, last_action_at?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            trackingNumber: (string) ($data['code'] ?? ''),
            name: $data['name'] ?? null,
            phone: $data['phone'] ?? null,
            address: $data['address'] ?? null,
            deliveryCost: isset($data['fee']) ? (float) $data['fee'] : null,
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            status: $data['status'] ?? null,
            statusReturn: $data['status_return'] ?? null,
            comment: $data['comment'] ?? null,
            reference: $data['reference'] ?? null,
            allowOpen: isset($data['allow_open']) ? (bool) $data['allow_open'] : null,
            allowTry: isset($data['allow_try']) ? (bool) $data['allow_try'] : null,
            optionExchange: isset($data['option_exchange']) ? (bool) $data['option_exchange'] : null,
            district: isset($data['district']) ? SenditDistrictDTO::fromArray($data['district']) : null,
            products: SenditParcelProductDTO::fromList($data['products'] ?? []),
            labelUrl: $data['labelUrl'] ?? null,
            lastActionAt: $data['last_action_at'] ?? null,
        );
    }

    /**
     * @param  array{data?: array<int, array<string, mixed>>}|array<int, array<string, mixed>>  $data
     * @return SenditParcelDTO[]
     */
    public static function fromList(array $data): array
    {
        $items = $data['data'] ?? $data;

        return array_map(fn (array $parcel) => self::fromArray($parcel), $items);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->trackingNumber,
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'fee' => $this->deliveryCost,
            'amount' => $this->amount,
            'status' => $this->status,
            'status_return' => $this->statusReturn,
            'comment' => $this->comment,
            'reference' => $this->reference,
            'allow_open' => $this->allowOpen,
            'allow_try' => $this->allowTry,
            'option_exchange' => $this->optionExchange,
            'district' => $this->district?->toArray(),
            'products' => array_map(fn ($product) => $product->toArray(), $this->products),
            'labelUrl' => $this->labelUrl,
            'last_action_at' => $this->lastActionAt,
        ];
    }
}
