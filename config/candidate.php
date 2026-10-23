<?php

$retention = env('CANDIDATE_RETENTION_DAYS');

return [

    /*
    | Password confirmation for Social Security number reveal, bank-detail
    | reveal, and identity-document download. This does not change the
    | three-hour application default used by other screens.
    */
    'password_timeout' => max(60, min(3600, (int) env('CANDIDATE_PASSWORD_TIMEOUT', 900))),

    /*
    | required rejects a document when the scanner is missing, times out, or
    | reports an infection. off is for isolated tests only. A real submission
    | uses required unless an operator has explicitly set the environment value.
    */
    'malware_scan' => env('CANDIDATE_MALWARE_SCAN', 'required') === 'off' ? 'off' : 'required',

    'clamav_command' => (string) env('CANDIDATE_CLAMAV_COMMAND', ''),

    'clamav_timeout' => max(1, min(30, (int) env('CANDIDATE_CLAMAV_TIMEOUT', 10))),

    /*
    | required rejects a public submission when the token is missing, invalid,
    | expired, or the provider cannot be reached. No provider secret belongs
    | in this file.
    */
    'captcha' => env('CANDIDATE_CAPTCHA', 'required') === 'off' ? 'off' : 'required',

    'turnstile_site_key' => (string) env('CANDIDATE_TURNSTILE_SITE_KEY', ''),

    'turnstile_secret' => (string) env('CANDIDATE_TURNSTILE_SECRET', ''),

    /*
    | Null means no retention period has been approved. Nothing is deleted
    | automatically while this stays null.
    */
    'retention_days' => $retention === null || $retention === '' ? null : max(0, (int) $retention),

];
