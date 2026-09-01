<?php

namespace App\DTOs\Coliix;

/**
 * Request payload to create a Coliix parcel, matching the form-data fields
 * expected by the api-parcels "add" action. Pass toArray() to
 * ColiixClient::addParcel().
 */
readonly class ColiixNewParcelDTO
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $marchandise,
        public int $marchandiseQty,
        /** City name — Coliix's "ville" takes a plain city name, not an id. */
        public string $ville,
        public string $adresse,
        public float $price,
        public ?string $note = null,
        public bool $stock = false,
        /** @var array<int, array{sku: string, qty: int}>|null Only used when $stock is true. */
        public ?array $products = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'phone' => $this->phone,
            'marchandise' => $this->marchandise,
            'marchandise_qty' => $this->marchandiseQty,
            'ville' => $this->ville,
            'adresse' => $this->adresse,
            'note' => $this->note,
            'stock' => (int) $this->stock,
            'price' => $this->price,
        ];

        if ($this->stock && $this->products) {
            foreach ($this->products as $index => $product) {
                $data["products[{$index}][sku]"] = $product['sku'];
                $data["products[{$index}][qty]"] = $product['qty'];
            }
        }

        return array_filter($data, fn ($value) => $value !== null);
    }
}
