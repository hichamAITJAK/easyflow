<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Stamps users.last_login_at on every successful sign-in, whatever the
 * method (password, two-factor, Google, "remember me" cookie). The Google
 * controller used to be the only writer, so password logins never showed
 * up in the business details "Last login" column.
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        }
    }
}
