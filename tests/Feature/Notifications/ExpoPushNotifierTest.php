<?php

use App\DTOs\Push\PushMessage;
use App\Interfaces\PushNotifierInterface;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Connectivity\Expo\ExpoPushNotifier;
use App\Services\Connectivity\Expo\NullPushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Only the two token-pruning tests touch the database; the rest are pure
// HTTP. Applied file-wide anyway — cheaper than splitting the file.
uses(RefreshDatabase::class);

function notifier(): ExpoPushNotifier
{
    return new ExpoPushNotifier(
        app(HttpFactory::class),
        'https://exp.host/--/api/v2/push/send',
        10,
    );
}

function okTickets(int $count): array
{
    return ['data' => array_fill(0, $count, ['status' => 'ok', 'id' => 'ticket'])];
}

test('sends a push with the expected payload shape', function () {
    Http::fake(['exp.host/*' => Http::response(okTickets(1), 200)]);

    notifier()->send(new PushMessage(
        token: 'ExponentPushToken[abc]',
        title: 'Title',
        body: 'Body',
        data: ['order_id' => 1],
    ));

    Http::assertSent(function ($request) {
        // The body is a list of messages, not a single object — Expo
        // accepts either, and batching means we always send the list form.
        $message = $request->data()[0];

        return $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $message['to'] === 'ExponentPushToken[abc]'
            && $message['title'] === 'Title'
            && $message['body'] === 'Body'
            && $message['data']['order_id'] === 1;
    });
});

test('many messages go out in a single request', function () {
    Http::fake(['exp.host/*' => Http::response(okTickets(3), 200)]);

    notifier()->sendMany([
        new PushMessage(token: 'ExponentPushToken[a]', title: 'T', body: 'B'),
        new PushMessage(token: 'ExponentPushToken[b]', title: 'T', body: 'B'),
        new PushMessage(token: 'ExponentPushToken[c]', title: 'T', body: 'B'),
    ]);

    Http::assertSentCount(1);
});

test('messages beyond Expo\'s 100-per-request limit are chunked', function () {
    Http::fake(['exp.host/*' => Http::response(okTickets(100), 200)]);

    $messages = array_map(
        fn (int $i): PushMessage => new PushMessage(token: "ExponentPushToken[{$i}]", title: 'T', body: 'B'),
        range(1, 150),
    );

    notifier()->sendMany($messages);

    Http::assertSentCount(2);
});

test('an empty batch sends nothing', function () {
    Http::fake();

    notifier()->sendMany([]);

    Http::assertNothingSent();
});

test('an HTTP failure is logged and swallowed, not thrown', function () {
    Http::fake(['exp.host/*' => Http::response('Service unavailable', 500)]);
    Log::spy();

    notifier()->send(new PushMessage(token: 'ExponentPushToken[abc]', title: 'T', body: 'B'));

    Log::shouldHaveReceived('warning')->once();
});

test('a network exception is caught and does not propagate', function () {
    Http::fake(function () {
        throw new ConnectionException('Could not connect.');
    });
    Log::spy();

    notifier()->send(new PushMessage(token: 'ExponentPushToken[abc]', title: 'T', body: 'B'));

    Log::shouldHaveReceived('warning')->once();
});

test('a DeviceNotRegistered ticket deletes that device token', function () {
    $user = User::factory()->create();

    DeviceToken::factory()->for($user)->create(['token' => 'ExponentPushToken[dead]']);
    DeviceToken::factory()->for($user)->create(['token' => 'ExponentPushToken[live]']);

    Http::fake(['exp.host/*' => Http::response([
        'data' => [
            ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'ok', 'id' => 'ticket'],
        ],
    ], 200)]);

    notifier()->sendMany([
        new PushMessage(token: 'ExponentPushToken[dead]', title: 'T', body: 'B'),
        new PushMessage(token: 'ExponentPushToken[live]', title: 'T', body: 'B'),
    ]);

    // Tickets are positional, so only the token matching the failed index
    // may be pruned — deleting the wrong row would silently stop a working
    // device from ever being notified again.
    expect(DeviceToken::where('token', 'ExponentPushToken[dead]')->exists())->toBeFalse()
        ->and(DeviceToken::where('token', 'ExponentPushToken[live]')->exists())->toBeTrue();
});

test('a non-fatal ticket error is logged but keeps the token', function () {
    $user = User::factory()->create();

    DeviceToken::factory()->for($user)->create(['token' => 'ExponentPushToken[abc]']);

    Http::fake(['exp.host/*' => Http::response([
        'data' => [['status' => 'error', 'details' => ['error' => 'MessageTooBig']]],
    ], 200)]);
    Log::spy();

    notifier()->send(new PushMessage(token: 'ExponentPushToken[abc]', title: 'T', body: 'B'));

    Log::shouldHaveReceived('warning')->once();
    expect(DeviceToken::where('token', 'ExponentPushToken[abc]')->exists())->toBeTrue();
});

test('the access token is sent as a bearer header when configured', function () {
    Http::fake(['exp.host/*' => Http::response(okTickets(1), 200)]);

    (new ExpoPushNotifier(app(HttpFactory::class), 'https://exp.host/--/api/v2/push/send', 10, 'secret'))
        ->send(new PushMessage(token: 'ExponentPushToken[abc]', title: 'T', body: 'B'));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret'));
});

test('the container binds the null notifier when expo is disabled', function () {
    config()->set('services.expo.enabled', false);
    app()->forgetInstance(PushNotifierInterface::class);

    expect(app(PushNotifierInterface::class))->toBeInstanceOf(NullPushNotifier::class);
});

test('the null notifier sends no HTTP traffic', function () {
    Http::fake();

    (new NullPushNotifier)->send(new PushMessage(token: 'ExponentPushToken[abc]', title: 'T', body: 'B'));

    Http::assertNothingSent();
});
