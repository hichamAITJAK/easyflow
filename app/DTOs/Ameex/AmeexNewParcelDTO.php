<?php

namespace App\DTOs\Ameex;

/**
 * Request payload to create an Ameex parcel, matching the form-data fields
 * expected by Parcels/Action/Type/Add. Pass toArray() to
 * AmeexClient::addParcel().
 *
 * Two Ameex-specific shapes worth noting:
 *
 *  - `city` is Ameex's own numeric city id, NOT a name. ForceLog and Coliix
 *    both accept a plain city name; Ameex does not.
 *  - `business` is the sender/expéditeur id. Omitting it is what produces
 *    the "Veuillez choisir l'expéditeur" rejection.
 *
 * Booleans are spelled inconsistently by the API itself — `open`/`try` take
 * "YES"/"NO", `fragile` takes 1/0, and `replace` takes "true"/"false" — so
 * each is emitted in the exact form that field expects rather than one
 * uniform casting rule.
 */
readonly class AmeexNewParcelDTO
{
    public function __construct(
        public string $receiver,
        public string $phone,
        /** Ameex's numeric city id — a city name is not accepted. */
        public string $city,
        public string $address,
        public float $cod,
        /** The sender ("expéditeur") id; required, and its own API id by default. */
        public ?string $business = null,
        public ?string $orderNum = null,
        public ?string $comment = null,
        public ?string $product = null,
        /** SIMPLE ships from the merchant; STOCK ships from stock Ameex holds. */
        public string $type = 'SIMPLE',
        public bool $canOpen = true,
        public bool $canTry = false,
        public bool $fragile = false,
        public bool $replace = false,
        public ?string $exchangeCode = null,
        /** @var array<int, array{id: string, qty: int}>|null Only used when $type is STOCK. */
        public ?array $products = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'business' => $this->business,
            'order_num' => $this->orderNum,
            'receiver' => $this->receiver,
            'phone' => $this->phone,
            'city' => $this->city,
            'address' => $this->address,
            'comment' => $this->comment,
            'product' => $this->product,
            'cod' => $this->cod,
            // Each flag in the spelling its own field expects.
            'open' => $this->canOpen ? 'YES' : 'NO',
            'try' => $this->canTry ? 'YES' : 'NO',
            'fragile' => $this->fragile ? 1 : 0,
            'replace' => $this->replace ? 'true' : 'false',
            'exchange_code' => $this->exchangeCode,
        ];

        if ($this->products) {
            foreach (array_values($this->products) as $index => $product) {
                $data["products[{$index}][id]"] = $product['id'];
                $data["products[{$index}][qty]"] = $product['qty'];
            }
        }

        return array_filter($data, fn ($value) => $value !== null);
    }
}
