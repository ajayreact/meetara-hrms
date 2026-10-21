<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated public addresses of proxies that may set X-Forwarded-For.
    | Leave this empty when the browser connects directly, including local WAMP.
    | A value of "*" is ignored. Forwarded headers from any other peer are not
    | used as proof of an office network.
    |
    */

    'trusts_all_proxies' => trim((string) env('TRUSTED_PROXIES', '')) === '*',

    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ), fn (string $proxy) => $proxy !== '' && $proxy !== '*')),

];
