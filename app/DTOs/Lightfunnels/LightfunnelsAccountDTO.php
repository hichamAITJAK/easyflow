<?php

namespace App\DTOs\Lightfunnels;

/**
 * Represents a Lightfunnels account's tracking pixels and integrations
 * (from LightfunnelsStoreService::getAccount()).
 */
readonly class LightfunnelsAccountDTO
{
    public function __construct(
        /** @var LightfunnelsPixelDTO[] */
        public array $facebookPixels,
        /** @var LightfunnelsPixelDTO[] */
        public array $snapchatPixels,
        /** @var LightfunnelsPixelDTO[] */
        public array $tiktokPixels,
        /** @var LightfunnelsIntegrationDTO[] */
        public array $integrations,
    ) {}

    /**
     * @param  array{account?: array{facebook_pixels?: array<int, array<string, mixed>>, snapchat_pixels?: array<int, array<string, mixed>>, tiktok_pixels?: array<int, array<string, mixed>>, integrations?: array<int, array<string, mixed>>}, facebook_pixels?: array<int, array<string, mixed>>, snapchat_pixels?: array<int, array<string, mixed>>, tiktok_pixels?: array<int, array<string, mixed>>, integrations?: array<int, array<string, mixed>>}  $data
     */
    public static function fromArray(array $data): self
    {
        $account = $data['account'] ?? $data;

        return new self(
            facebookPixels: LightfunnelsPixelDTO::fromList($account['facebook_pixels'] ?? []),
            snapchatPixels: LightfunnelsPixelDTO::fromList($account['snapchat_pixels'] ?? []),
            tiktokPixels: LightfunnelsPixelDTO::fromList($account['tiktok_pixels'] ?? []),
            integrations: LightfunnelsIntegrationDTO::fromList($account['integrations'] ?? []),
        );
    }

    /**
     * @return array{facebook_pixels: array<int, array<string, mixed>>, snapchat_pixels: array<int, array<string, mixed>>, tiktok_pixels: array<int, array<string, mixed>>, integrations: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'facebook_pixels' => array_map(fn ($p) => $p->toArray(), $this->facebookPixels),
            'snapchat_pixels' => array_map(fn ($p) => $p->toArray(), $this->snapchatPixels),
            'tiktok_pixels' => array_map(fn ($p) => $p->toArray(), $this->tiktokPixels),
            'integrations' => array_map(fn ($i) => $i->toArray(), $this->integrations),
        ];
    }
}
