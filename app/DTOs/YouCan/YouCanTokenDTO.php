<?php

namespace App\DTOs\YouCan;

/**
 * Represents a YouCan API authentication token response.
 * Returned by login() and exchangeToken().
 */
readonly class YouCanTokenDTO
{
    public function __construct(
        public string $accessToken,
        public ?string $tokenType,
        public ?int $expiresIn,
        public ?string $refreshToken,
    ) {}

    /**
     * @param  array{
     *     token?: ?string,
     *     access_token?: ?string,
     *     token_type?: ?string,
     *     expires_in?: int|string,
     *     refresh_token?: ?string,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: $data['token'] ?? $data['access_token'] ?? '',
            tokenType: $data['token_type'] ?? null,
            expiresIn: isset($data['expires_in']) ? (int) $data['expires_in'] : null,
            refreshToken: $data['refresh_token'] ?? null,
        );
    }

    /**
     * @return array{
     *     access_token: string,
     *     token_type: ?string,
     *     expires_in: ?int,
     *     refresh_token: ?string,
     * }
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
        ];
    }
}
