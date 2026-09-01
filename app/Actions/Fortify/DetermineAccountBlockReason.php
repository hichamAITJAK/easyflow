<?php

namespace App\Actions\Fortify;

use App\Enums\BusinessStatus;
use App\Enums\LoginContext;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

class DetermineAccountBlockReason
{
    /**
     * Roles that only have access to the mobile app, never the web app.
     *
     * @var list<UserRole>
     */
    private const MOBILE_ONLY_ROLES = [
        UserRole::FULFILMENT_AGENT,
    ];

    /**
     * Return the reason a user cannot log in, or null if their account and
     * business are both in a usable state.
     */
    public function __invoke(User $user, LoginContext $context = LoginContext::WEB): ?string
    {
        return match (true) {
            $context === LoginContext::WEB
                && \in_array($user->role, self::MOBILE_ONLY_ROLES, true) => 'mobile-only-role',
            $user->status === UserStatus::DISABLED => 'user-disabled',
            $user->status === UserStatus::INVITED => 'user-invited',
            $user->business?->status === BusinessStatus::SUSPENDED => 'business-suspended',
            $user->business?->status === BusinessStatus::CANCELLED => 'business-cancelled',
            default => null,
        };
    }
}
