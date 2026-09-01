<?php

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function actingAsApiUser(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $token = $user->createToken('device')->plainTextToken;

    return [$user, $token];
}

test('a device token can be registered', function () {
    [$user, $token] = actingAsApiUser();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.device-tokens.store'), [
            'token' => 'ExponentPushToken[abc]',
            'device_type' => 'ios',
            'device_name' => 'iPhone 15',
        ]);

    $response->assertCreated();

    $deviceToken = DeviceToken::where('token', 'ExponentPushToken[abc]')->firstOrFail();
    expect($deviceToken->user_id)->toBe($user->id);
    expect($deviceToken->device_type)->toBe('ios');
});

test('re-registering the same token reassigns it to the current user instead of duplicating it', function () {
    [$firstUser] = actingAsApiUser();
    [$secondUser, $secondToken] = actingAsApiUser();

    DeviceToken::create(['user_id' => $firstUser->id, 'token' => 'ExponentPushToken[shared]']);

    $response = $this->withHeader('Authorization', "Bearer {$secondToken}")
        ->postJson(route('api.device-tokens.store'), [
            'token' => 'ExponentPushToken[shared]',
        ]);

    $response->assertCreated();
    expect(DeviceToken::where('token', 'ExponentPushToken[shared]')->count())->toBe(1);
    expect(DeviceToken::where('token', 'ExponentPushToken[shared]')->first()->user_id)->toBe($secondUser->id);
});

test('a device token can be unregistered', function () {
    [$user, $token] = actingAsApiUser();
    DeviceToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[abc]']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson(route('api.device-tokens.destroy'), ['token' => 'ExponentPushToken[abc]']);

    $response->assertOk();
    expect(DeviceToken::where('token', 'ExponentPushToken[abc]')->exists())->toBeFalse();
});

test('a user cannot unregister a device token belonging to someone else', function () {
    [$owner] = actingAsApiUser();
    [, $attackerToken] = actingAsApiUser();
    DeviceToken::create(['user_id' => $owner->id, 'token' => 'ExponentPushToken[abc]']);

    $this->withHeader('Authorization', "Bearer {$attackerToken}")
        ->deleteJson(route('api.device-tokens.destroy'), ['token' => 'ExponentPushToken[abc]']);

    expect(DeviceToken::where('token', 'ExponentPushToken[abc]')->exists())->toBeTrue();
});

test('device-token routes reject a request with no token', function () {
    $this->postJson(route('api.device-tokens.store'), ['token' => 'x'])->assertUnauthorized();
    $this->deleteJson(route('api.device-tokens.destroy'), ['token' => 'x'])->assertUnauthorized();
});
