<?php

namespace App\Actions\Fortify;

use App\Enums\BusinessStatus;
use App\Enums\LoginContext;
use App\Enums\UserStatus;
use App\Models\User;

class DetermineAccountBlockReason
{
    /**
     * Return the reason a user cannot log in, or null if their account and
     * business are both in a usable state.
     *
     * No role is barred from the web app. Fulfilment agents were once
     * mobile-only here, but they now work the scan workspace in a browser
     * (see the fulfilment routes) and read their own commission entries
     * there, so the restriction was removed rather than widened. What each
     * role may actually reach is decided by the route gates, which is where
     * per-screen access belongs — this class only answers whether the
     * account and its business are usable at all.
     *
     * $context is still accepted because the mobile API passes it and
     * callers are written around it; it no longer changes the outcome.
     */
    public function __invoke(User $user, LoginContext $context = LoginContext::WEB): ?string
    {
        return match (true) {
            $user->status === UserStatus::DISABLED => 'user-disabled',
            $user->status === UserStatus::INVITED => 'user-invited',
            $user->business?->status === BusinessStatus::SUSPENDED => 'business-suspended',
            $user->business?->status === BusinessStatus::CANCELLED => 'business-cancelled',
            default => null,
        };
    }
}
