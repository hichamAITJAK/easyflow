<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform owner account
    |--------------------------------------------------------------------------
    |
    | The super admin created by `php artisan db:seed`. Read through config
    | rather than env() inside the seeder because env() returns null once
    | the configuration is cached, which is the normal state in production.
    |
    | Left unset outside local/testing, the seeder skips the account instead
    | of inventing one — a guessable default on a public server is worse
    | than no account at all.
    |
    */

    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

];
