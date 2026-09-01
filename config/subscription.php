<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trial
    |--------------------------------------------------------------------------
    |
    | Every newly onboarded business starts on a free trial. Trial ends hard
    | at ends_at — no grace period, the block screen is the decision moment.
    |
    */

    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 14),

    'trial_limits' => [
        'max_stores' => 1,
        'max_delivery_couriers' => 1,
        'max_confirmation_agents' => 1,
        'max_fulfilment_agents' => 1,
        'daily_orders' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grace period (paid subscriptions only)
    |--------------------------------------------------------------------------
    |
    | Bank transfers take days; an expired paid subscription keeps access for
    | this many days with a warning banner before the app is blocked.
    |
    */

    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 5),

    /*
    |--------------------------------------------------------------------------
    | Renewal reminders
    |--------------------------------------------------------------------------
    |
    | Days-remaining thresholds at which the daily check fires the
    | SubscriptionExpiringSoon event (once per threshold).
    |
    */

    'reminder_days' => [15, 7, 3, 1],

    /*
    |--------------------------------------------------------------------------
    | Manual payment details
    |--------------------------------------------------------------------------
    |
    | Shown on the block screen so the business can pay by bank transfer.
    |
    */

    'bank' => [
        'account_holder' => env('SUBSCRIPTION_BANK_ACCOUNT_HOLDER', 'AL STEIN'),
        'bank_name' => env('SUBSCRIPTION_BANK_NAME', 'CIH BANK'),
        'rib' => env('SUBSCRIPTION_BANK_RIB', '1234567898765472'),
    ],

    'contact_whatsapp' => env('SUBSCRIPTION_CONTACT_WHATSAPP', '0666666666'),

];
