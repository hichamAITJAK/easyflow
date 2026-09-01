<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class BusinessAdmins
{
    /**
     * The active admins of a business — the recipients of every
     * subscription-related notification.
     *
     * @return Collection<int, User>
     */
    public static function of(Business $business): Collection
    {
        return $business->users()
            ->where('role', UserRole::ADMIN)
            ->where('status', UserStatus::ACTIVE)
            ->get();
    }
}
