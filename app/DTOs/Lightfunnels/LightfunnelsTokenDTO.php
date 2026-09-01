<?php

namespace App\DTOs\Lightfunnels;

/**
 * Represents a Lightfunnels API authentication token response.
 * Returned by exchangeToken() during OAuth.
 */
readonly class LightfunnelsTokenDTO
{
    public function __construct(
        public string $accessToken,
    ) {}

    /**
     * @param  array{access_token?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: $data['access_token'] ?? '',
        );
    }

    /**
     * @return array{access_token: string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
        ];
    }
}
