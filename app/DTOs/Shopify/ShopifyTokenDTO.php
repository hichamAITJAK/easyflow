<?php

namespace App\DTOs\Shopify;

/**
 * Represents a Shopify API authentication token response.
 * Returned by exchangeToken() during OAuth.
 */
readonly class ShopifyTokenDTO
{
    public function __construct(
        public string $accessToken,
        public string $scope,
        public ?int $expiresIn,
        public ?string $refreshToken,
    ) {}

    /**
     * @param array{
     *     access_token?: string|null,
     *     scope?: string|null,
     *     expires_in?: int|string|null,
     *     refresh_token?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: $data['access_token'] ?? '',
            scope: $data['scope'] ?? '',
            expiresIn: isset($data['expires_in']) ? (int) $data['expires_in'] : null,
            refreshToken: $data['refresh_token'] ?? null,
        );
    }

    /**
     * @return array{
     *     access_token: string,
     *     scope: string,
     *     expires_in: int|null,
     *     refresh_token: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'scope' => $this->scope,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
        ];
    }
}
