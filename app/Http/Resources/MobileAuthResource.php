<?php

namespace App\Http\Resources;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

/**
 * The single login response shape returned by every /api/mobile/auth/*
 * endpoint that issues a token (password and passkey login), so the mobile
 * client only ever has to parse one contract regardless of which method
 * was used to sign in.
 */
class MobileAuthResource
{
    /**
     * @return array{token: string, user: array{id: int, name: string, email: string, phone: string|null, avatar: string|null, role: string, business_id: int|null}}
     */
    public static function make(User $user, NewAccessToken $token): array
    {
        return [
            'token' => $token->plainTextToken,
            'user' => MobileUserResource::make($user),
        ];
    }
}
