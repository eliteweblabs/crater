<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Contact API (master identity service)
    |--------------------------------------------------------------------------
    |
    | Thin client config for the standalone contact-api service that acts as
    | the master identity store for contacts across Crater, Cal.com, etc.
    |
    | Auto-enabled whenever CONTACT_API_URL is set. Set CONTACT_API_ENABLED=0
    | to explicitly disable even when URL is configured (e.g. for local dev).
    */

    'url'     => env('CONTACT_API_URL'),
    'key'     => env('CONTACT_API_KEY'),
    'enabled' => env('CONTACT_API_ENABLED', null) !== null
        ? filter_var(env('CONTACT_API_ENABLED'), FILTER_VALIDATE_BOOLEAN)
        : !empty(env('CONTACT_API_URL')),

    'timeout' => env('CONTACT_API_TIMEOUT', 3),

    'system_name' => env('CONTACT_API_SYSTEM_NAME', 'crater'),
];
