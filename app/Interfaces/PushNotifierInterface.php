<?php

namespace App\Interfaces;

use App\DTOs\Push\PushMessage;

/**
 * The push transport, behind an interface so callers depend on "something
 * can deliver a push" rather than on Expo specifically.
 *
 * Two reasons this is not just the concrete client:
 *
 * 1. Tests bind a fake instead of faking HTTP, so a test that cares about
 *    "was the agent notified" asserts on messages rather than on request
 *    bodies aimed at exp.host.
 * 2. Expo push tokens are tied to Expo's build service. If the app ever
 *    ships bare (direct APNs/FCM), that swaps the binding in
 *    AppServiceProvider and nothing upstream changes.
 */
interface PushNotifierInterface
{
    /**
     * Deliver one message. Implementations must not throw — a push is a
     * best-effort convenience and must never fail the business action
     * (order assignment, status change) that triggered it.
     */
    public function send(PushMessage $message): void;

    /**
     * Deliver many messages in as few network round-trips as the
     * transport allows.
     *
     * Separate from send() because Expo accepts up to 100 messages per
     * request: fanning a single notification out to a whole team is one
     * call here, versus one call per device if callers looped over
     * send() themselves.
     *
     * @param  array<int, PushMessage>  $messages
     */
    public function sendMany(array $messages): void;
}
