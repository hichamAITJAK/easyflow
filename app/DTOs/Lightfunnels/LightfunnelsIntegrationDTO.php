<?php

namespace App\DTOs\Lightfunnels;

/**
 * Represents a third-party integration attached to a Lightfunnels account.
 */
readonly class LightfunnelsIntegrationDTO
{
    public function __construct(
        public string $id,
        public ?string $label,
        public ?string $platform,
        public mixed $details,
    ) {}

    /**
     * @param  array{id?: mixed, label?: ?string, platform?: ?string, details?: mixed}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            label: $data['label'] ?? null,
            platform: $data['platform'] ?? null,
            details: $data['details'] ?? null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @return LightfunnelsIntegrationDTO[]
     */
    public static function fromList(array $data): array
    {
        return array_map(fn (array $integration) => self::fromArray($integration), $data);
    }

    /**
     * @return array{id: string, label: ?string, platform: ?string, details: mixed}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'platform' => $this->platform,
            'details' => $this->details,
        ];
    }
}
