<?php

namespace App\DTOs\Push;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One push notification aimed at one device token.
 *
 * Exists so the notifier's signature is a single typed value instead of
 * four positional scalars — with (string $token, string $title, string
 * $body, array $data) the two strings in the middle are trivial to
 * transpose, and nothing catches it.
 *
 * @implements Arrayable<string, mixed>
 */
readonly class PushMessage implements Arrayable
{
    /**
     * @param  array<string, mixed>  $data  Payload the app routes on when the
     *                                      notification is tapped (see the
     *                                      mobile app's routeForPush).
     * @param  string|null  $channelId  Android notification channel. Must match
     *                                  a channel the app created, or Android
     *                                  silently drops the notification.
     */
    public function __construct(
        public string $token,
        public string $title,
        public string $body,
        public array $data = [],
        public ?string $sound = 'default',
        public ?string $channelId = 'default',
        public ?int $badge = null,
    ) {}

    /**
     * The wire shape Expo's push API expects.
     *
     * Nulls are stripped rather than sent explicitly: Expo treats a
     * present-but-null `sound` differently from an absent one, and the
     * absent case is the sane default.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'to' => $this->token,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'sound' => $this->sound,
            'channelId' => $this->channelId,
            'badge' => $this->badge,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
