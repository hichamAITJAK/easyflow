<?php

namespace App\DTOs\YouCan;

/**
 * Represents a YouCan customer (from listCustomers() / getCustomer()).
 *
 * When fetched with `include=orders`, the raw `orders` subresource is mapped
 * into YouCanOrderDTO instances; otherwise `orders` is empty.
 */
readonly class YouCanCustomerDTO
{
    public function __construct(
        public string $id,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $fullName,
        public ?string $email,
        public ?string $phone,
        public ?string $country,
        public ?string $region,
        public ?string $city,
        public ?string $location,
        public ?string $notes,
        public ?string $avatar,
        /** @var YouCanAddressDTO[] */
        public array $addresses,
        /** @var YouCanOrderDTO[] */
        public array $orders,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param  array{
     *     customer?: array<string, mixed>,
     *     id?: string|int,
     *     first_name?: ?string,
     *     last_name?: ?string,
     *     full_name?: ?string,
     *     email?: ?string,
     *     phone?: ?string,
     *     country?: ?string,
     *     region?: ?string,
     *     city?: ?string,
     *     location?: ?string,
     *     notes?: ?string,
     *     avatar?: ?string,
     *     address?: array<int, array<string, mixed>>,
     *     orders?: array<int, array<string, mixed>>,
     *     created_at?: ?string,
     *     updated_at?: ?string,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $customer = $data['customer'] ?? $data;

        $addresses = array_map(
            fn (array $address) => YouCanAddressDTO::fromArray($address),
            $customer['address'] ?? []
        );

        $orders = array_map(
            fn (array $order) => YouCanOrderDTO::fromArray($order),
            $customer['orders'] ?? []
        );

        return new self(
            id: (string) ($customer['id'] ?? ''),
            firstName: $customer['first_name'] ?? null,
            lastName: $customer['last_name'] ?? null,
            fullName: $customer['full_name'] ?? trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? '')) ?: null,
            email: $customer['email'] ?? null,
            phone: $customer['phone'] ?? null,
            country: $customer['country'] ?? null,
            region: $customer['region'] ?? null,
            city: $customer['city'] ?? null,
            location: $customer['location'] ?? null,
            notes: $customer['notes'] ?? null,
            avatar: $customer['avatar'] ?? null,
            addresses: $addresses,
            orders: $orders,
            createdAt: $customer['created_at'] ?? null,
            updatedAt: $customer['updated_at'] ?? null,
        );
    }

    /**
     * @param  array{data?: array<int, array<string, mixed>>}|array<int, array<string, mixed>>  $data
     * @return YouCanCustomerDTO[]
     */
    public static function fromList(array $data): array
    {
        $items = $data['data'] ?? $data;

        return array_map(
            fn (array $customer) => self::fromArray($customer),
            $items
        );
    }

    /**
     * @return array{
     *     id: string,
     *     first_name: ?string,
     *     last_name: ?string,
     *     full_name: ?string,
     *     email: ?string,
     *     phone: ?string,
     *     country: ?string,
     *     region: ?string,
     *     city: ?string,
     *     location: ?string,
     *     notes: ?string,
     *     avatar: ?string,
     *     addresses: array<int, array<string, mixed>>,
     *     orders: array<int, array<string, mixed>>,
     *     created_at: ?string,
     *     updated_at: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->fullName,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'location' => $this->location,
            'notes' => $this->notes,
            'avatar' => $this->avatar,
            'addresses' => array_map(fn ($a) => $a->toArray(), $this->addresses),
            'orders' => array_map(fn ($o) => $o->toArray(), $this->orders),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
