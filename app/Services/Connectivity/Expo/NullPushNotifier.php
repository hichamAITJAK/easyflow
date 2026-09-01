<?php

namespace App\Services\Connectivity\Expo;

use App\DTOs\Push\PushMessage;
use App\Interfaces\PushNotifierInterface;
use Illuminate\Support\Facades\Log;

/**
 * Bound in place of the real notifier when services.expo.enabled is off —
 * local development and CI, where an actual send would either fail (no
 * network) or, worse, succeed and push a test notification to someone's
 * real phone from a seeded device token.
 *
 * Logs at debug so a developer can still confirm a push *would* have gone
 * out, and which one, without any traffic leaving the machine.
 */
class NullPushNotifier implements PushNotifierInterface
{
    public function send(PushMessage $message): void
    {
        $this->sendMany([$message]);
    }

    /**
     * @param  array<int, PushMessage>  $messages
     */
    public function sendMany(array $messages): void
    {
        foreach ($messages as $message) {
            Log::debug('Push suppressed (expo disabled)', [
                'token' => $message->token,
                'title' => $message->title,
                'data' => $message->data,
            ]);
        }
    }
}
