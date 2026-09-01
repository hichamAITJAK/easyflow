<?php

namespace App\Services\Connectivity\Expo;

use App\DTOs\Push\PushMessage;
use App\Interfaces\PushNotifierInterface;
use App\Models\DeviceToken;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Expo push transport (PRD section 9: "the backend calls Expo's push API
 * directly — no client-side polling").
 *
 * Docs: https://docs.expo.dev/push-notifications/sending-notifications/
 *
 * The HTTP client is injected rather than used via the Http facade so a
 * test can hand in a faked factory without reaching for global state.
 */
class ExpoPushNotifier implements PushNotifierInterface
{
    /**
     * Expo rejects request bodies above 100 messages.
     */
    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $endpoint,
        private readonly int $timeout,
        private readonly ?string $accessToken = null,
    ) {}

    public function send(PushMessage $message): void
    {
        $this->sendMany([$message]);
    }

    /**
     * @param  list<PushMessage>  $messages
     */
    public function sendMany(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        foreach (array_chunk($messages, self::CHUNK_SIZE) as $chunk) {
            $this->dispatchChunk($chunk);
        }
    }

    /**
     * @param  list<PushMessage>  $chunk
     */
    private function dispatchChunk(array $chunk): void
    {
        try {
            $request = $this->http->asJson()
                ->acceptJson()
                ->timeout($this->timeout);

            // Only required once a project turns on "enhanced security" in
            // Expo; absent otherwise, so it stays optional rather than a
            // hard config requirement.
            if ($this->accessToken !== null && $this->accessToken !== '') {
                $request = $request->withToken($this->accessToken);
            }

            $response = $request->post(
                $this->endpoint,
                array_map(fn (PushMessage $message): array => $message->toArray(), $chunk),
            );

            if ($response->failed()) {
                Log::warning('Expo push send failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'count' => count($chunk),
                ]);

                return;
            }

            /** @var array<int, array<string, mixed>> $tickets */
            $tickets = $response->json('data') ?? [];

            $this->handleTickets($chunk, $tickets);
        } catch (Throwable $e) {
            // Swallowed on purpose: a push is best-effort and must never
            // fail the business action that triggered it.
            Log::warning('Expo push send threw', [
                'error' => $e->getMessage(),
                'count' => count($chunk),
            ]);
        }
    }

    /**
     * Expo answers with one ticket per message, positionally matched to
     * the request. A ticket with status "error" and details.error
     * "DeviceNotRegistered" means the token is permanently dead — the app
     * was uninstalled, or the OS rotated it.
     *
     * Those rows are deleted rather than retried: Expo's own docs require
     * it, and a token that can never deliver otherwise stays in
     * device_tokens forever, costing a wasted message on every future
     * fan-out.
     *
     * @param  array<int, PushMessage>  $chunk
     * @param  array<int, array<string, mixed>>  $tickets
     */
    private function handleTickets(array $chunk, array $tickets): void
    {
        $deadTokens = [];

        foreach ($tickets as $index => $ticket) {
            if (($ticket['status'] ?? null) !== 'error') {
                continue;
            }

            $error = $ticket['details']['error'] ?? null;
            $token = $chunk[$index]->token ?? null;

            if ($error === 'DeviceNotRegistered' && $token !== null) {
                $deadTokens[] = $token;

                continue;
            }

            Log::warning('Expo push rejected', [
                'error' => $error,
                'message' => $ticket['message'] ?? null,
                'token' => $token,
            ]);
        }

        if ($deadTokens !== []) {
            DeviceToken::whereIn('token', $deadTokens)->delete();

            Log::info('Pruned unregistered device tokens', ['count' => count($deadTokens)]);
        }
    }
}
