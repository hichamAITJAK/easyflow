<?php

namespace App\Http\Resources;

use App\Models\User;

/**
 * The user object shape shared by every /api/mobile/* endpoint that returns
 * a user — login, passkey login, and profile update — so the mobile client
 * always parses the same contract regardless of which action produced it.
 */
class MobileUserResource
{
    /**
     * @return array{id: int, name: string, email: string, phone: string|null, avatar: string|null, role: string, business_id: int|null}
     */
    public static function make(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'role' => $user->role->value,
            'business_id' => $user->business_id,
        ];
    }
}
