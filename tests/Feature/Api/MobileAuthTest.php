<?php

use App\Actions\Mobile\DecodePasskeyVerificationPayload;
use App\Enums\BusinessStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

uses(RefreshDatabase::class);

/**
 * A real WebAuthn assertion requires a genuine authenticator's private-key
 * signature, which can't be fabricated in a feature test without
 * reimplementing WebAuthn's crypto — so DecodePasskeyVerificationPayload and
 * VerifyPasskey (Fortify's own, already-tested building blocks) are swapped
 * for these tests instead of driving the full ceremony. What's under test
 * here is this app's wiring around them: the stateless options round-trip,
 * block-reason checks, and token issuance.
 */
function fakeCredentialPayload(): array
{
    return [
        'id' => 'credential-id',
        'rawId' => 'cmF3LWlk',
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => 'eyJ0eXBlIjoid2ViYXV0aG4uZ2V0In0',
            'authenticatorData' => 'YXV0aGVudGljYXRvci1kYXRh',
            'signature' => 'c2ln',
        ],
    ];
}

function mockPasskeyDecoding(): void
{
    test()->mock(DecodePasskeyVerificationPayload::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn([
            Mockery::mock(PublicKeyCredential::class),
            PublicKeyCredentialRequestOptions::create(challenge: 'challenge'),
        ]);
    });
}

test('passkey login-options returns discoverable options and an options token', function () {
    $response = $this->getJson(route('api.mobile.auth.passkey.login-options'));

    $response->assertOk();
    $response->assertJsonStructure(['options', 'options_token']);
    expect($response->json('options_token'))->toBeString();
});

test('a user can log in with a valid passkey and receives a token', function () {
    $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
    $passkey = Passkey::forceCreate([
        'user_id' => $user->id,
        'name' => 'iPhone',
        'credential_id' => 'credential-id',
        'credential' => [],
    ]);
    $passkey->setRelation('user', $user);

    mockPasskeyDecoding();
    $this->mock(VerifyPasskey::class, function ($mock) use ($passkey) {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $response = $this->postJson(route('api.mobile.auth.passkey.login'), [
        'credential' => fakeCredentialPayload(),
        'options_token' => 'opaque-token',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'phone', 'avatar', 'role', 'business_id']]);
    expect($user->tokens()->count())->toBe(1);
});

test('passkey login is rejected when verification fails', function () {
    mockPasskeyDecoding();
    $this->mock(VerifyPasskey::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andThrow(InvalidPasskeyException::make('Passkey not recognized.'));
    });

    $response = $this->postJson(route('api.mobile.auth.passkey.login'), [
        'credential' => fakeCredentialPayload(),
        'options_token' => 'opaque-token',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('credential');
});

test('passkey login is rejected for a disabled user', function () {
    $user = User::factory()->create(['status' => UserStatus::DISABLED]);
    $passkey = Passkey::forceCreate([
        'user_id' => $user->id,
        'name' => 'iPhone',
        'credential_id' => 'credential-id',
        'credential' => [],
    ]);
    $passkey->setRelation('user', $user);

    mockPasskeyDecoding();
    $this->mock(VerifyPasskey::class, function ($mock) use ($passkey) {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $response = $this->postJson(route('api.mobile.auth.passkey.login'), [
        'credential' => fakeCredentialPayload(),
        'options_token' => 'opaque-token',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertUnprocessable();
    expect($user->tokens()->count())->toBe(0);
});

test('decoding rejects a malformed credential', function () {
    $decode = app(DecodePasskeyVerificationPayload::class);

    try {
        $decode->decodeCredential(['not' => 'a credential']);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('credential');
    }
});

test('decoding rejects a malformed options token', function () {
    $decode = app(DecodePasskeyVerificationPayload::class);

    try {
        $decode->decodeOptions('not-a-valid-options-token');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('options_token');
    }
});

test('passkey login endpoint rejects a request missing required fields', function () {
    $response = $this->postJson(route('api.mobile.auth.passkey.login'), [
        'device_name' => 'iPhone 15',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['credential', 'options_token']);
});

test('a user can log in with valid email/password credentials and receives a token', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
        'status' => UserStatus::ACTIVE,
    ]);

    $response = $this->postJson(route('api.mobile.auth.login'), [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'phone', 'avatar', 'role', 'business_id']]);
    expect($user->tokens()->count())->toBe(1);
});

test('email/password login is rejected with the wrong password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
        'status' => UserStatus::ACTIVE,
    ]);

    $response = $this->postJson(route('api.mobile.auth.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('email');
    expect($user->tokens()->count())->toBe(0);
});

test('email/password login is rejected for a user whose business is suspended', function () {
    $user = makeBusinessUser([
        'password' => bcrypt('password123'),
        'status' => UserStatus::ACTIVE,
    ]);
    $user->business->update(['status' => BusinessStatus::SUSPENDED]);

    $response = $this->postJson(route('api.mobile.auth.login'), [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertUnprocessable();
    expect($user->tokens()->count())->toBe(0);
});

test('an authenticated user can log out via the mobile endpoint, revoking only the current token', function () {
    $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
    $token = $user->createToken('iPhone 15');
    $user->createToken('iPad');

    $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->postJson(route('api.mobile.auth.logout'));

    $response->assertOk();
    expect($user->tokens()->count())->toBe(1);
});

test('mobile logout rejects a request with no token', function () {
    $this->postJson(route('api.mobile.auth.logout'))->assertUnauthorized();
});
