<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated public addresses of proxies that may set X-Forwarded-For.
    | Leave this empty when the browser connects directly, including local WAMP
    | and a LAN address such as 192.168.x.x. Loopback and private proxy
    | addresses are ignored, because trusting them would allow the browser to
    | supply the client address. A value of "*" is ignored. Forwarded headers
    | from any other peer are not used as proof of an office network.
    |
    */

    'trusts_all_proxies' => trim((string) env('TRUSTED_PROXIES', '')) === '*',

    /*
    | How many past days an employee may ask to correct. Future dates are never accepted.
    */
    'correction_days' => max(1, (int) env('ATTENDANCE_CORRECTION_DAYS', 14)),

    /*
    | Used only when both the assigned office and the company have no valid timezone.
    | An India office with a blank timezone follows the company timezone first.
    */
    'fallback_timezone' => 'Asia/Kolkata',

    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ), fn (string $proxy) => $proxy !== '' && $proxy !== '*')),

];
