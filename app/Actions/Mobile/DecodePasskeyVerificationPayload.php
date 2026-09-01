<?php

namespace App\Actions\Mobile;

use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Deserializes the two pieces of the stateless passkey verification
 * round-trip (see MobileAuthController's class docblock): the credential
 * the device just produced, and the options token the client echoed back
 * from the login-options call.
 */
class DecodePasskeyVerificationPayload
{
    /**
     * @param  array<string, mixed>  $credential
     * @return array{0: PublicKeyCredential, 1: PublicKeyCredentialRequestOptions}
     */
    public function __invoke(array $credential, string $optionsToken): array
    {
        return [
            $this->decodeCredential($credential),
            $this->decodeOptions($optionsToken),
        ];
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    public function decodeCredential(array $credential): PublicKeyCredential
    {
        try {
            return WebAuthn::fromJson(
                json_encode($credential, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'credential' => __('Invalid credential format.'),
            ]);
        }
    }

    public function decodeOptions(string $optionsToken): PublicKeyCredentialRequestOptions
    {
        try {
            return WebAuthn::fromJson($optionsToken, PublicKeyCredentialRequestOptions::class);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'options_token' => __('Invalid or expired passkey challenge. Please try again.'),
            ]);
        }
    }
}
