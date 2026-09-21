<?php

return [
    'api_key' => env('POSTHOG_PROJECT_TOKEN', ''),
    'host' => env('POSTHOG_HOST', 'https://us.i.posthog.com'),
    'disabled' => env('POSTHOG_DISABLED', false),
    'timeout' => (int) env('POSTHOG_TIMEOUT', 2),
    'connect_timeout' => (int) env('POSTHOG_CONNECT_TIMEOUT', 1),
    'debug' => env('APP_DEBUG', false),
];
