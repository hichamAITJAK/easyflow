<?php

namespace App\DTOs\Sendit;

/**
 * Represents a single product line item within a Sendit parcel.
 */
readonly class SenditParcelProductDTO
{
    public function __construct(
        public ?string $code,
        public ?string $reference,
        public ?string $name,
        public int $quantity,
    ) {}

    /**
     * @param  array{code?: ?string, reference?: ?string, name?: ?string, quantity?: string|int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: $data['code'] ?? null,
            reference: $data['reference'] ?? null,
            name: $data['name'] ?? null,
            quantity: (int) ($data['quantity'] ?? 0),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @return SenditParcelProductDTO[]
     */
    public static function fromList(array $data): array
    {
        return array_map(fn (array $product) => self::fromArray($product), $data);
    }

    /** @return array{code: ?string, reference: ?string, name: ?string, quantity: int} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'reference' => $this->reference,
            'name' => $this->name,
            'quantity' => $this->quantity,
        ];
    }
}
