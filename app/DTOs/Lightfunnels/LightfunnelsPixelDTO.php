<?php

namespace App\DTOs\Lightfunnels;

/**
 * Represents a single tracking pixel (Facebook, Snapchat, or TikTok) on a Lightfunnels account.
 */
readonly class LightfunnelsPixelDTO
{
    public function __construct(
        public ?string $label,
        public string $value,
    ) {}

    /**
     * @param  array{label?: ?string, value?: mixed}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: $data['label'] ?? null,
            value: (string) ($data['value'] ?? ''),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     * @return LightfunnelsPixelDTO[]
     */
    public static function fromList(array $data): array
    {
        return array_map(fn (array $pixel) => self::fromArray($pixel), $data);
    }

    /**
     * @return array{label: ?string, value: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'value' => $this->value,
        ];
    }
}
