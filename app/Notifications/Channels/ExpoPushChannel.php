<?php

namespace App\Notifications\Channels;

use App\DTOs\Push\PushMessage;
use App\Interfaces\PushNotifierInterface;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Fans a notification out to every device token the notifiable (always a
 * User here — device_tokens is user-scoped, PRD "supports multiple devices
 * per user") has registered, rather than sending once to a single token —
 * an agent logged in on both a phone and a tablet gets the push on both.
 *
 * Built as one sendMany() call rather than a send() per device: Expo takes
 * up to 100 messages per request, so a two-device agent costs one HTTP
 * round-trip instead of two.
 */
class ExpoPushChannel
{
    public function __construct(private readonly PushNotifierInterface $notifier) {}

    public function send(User $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toExpoPush')) {
            return;
        }

        /** @var array{title: string, body: string, data?: array<string, mixed>} $payload */
        $payload = $notification->toExpoPush($notifiable);

        $messages = $notifiable->deviceTokens
            ->map(fn ($deviceToken): PushMessage => new PushMessage(
                token: $deviceToken->token,
                title: $payload['title'],
                body: $payload['body'],
                data: $payload['data'] ?? [],
            ))
            ->all();

        $this->notifier->sendMany($messages);
    }
}
