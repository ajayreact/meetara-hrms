<?php

return [

    /*
    | Company Admin invitation links expire after this many hours.
    | Resend replaces the previous token and starts this interval again.
    */
    'company_admin_invitation_hours' => 168,

    /*
    | Employee invitation links expire after this many hours.
    | Resend replaces the previous token and starts this interval again.
    */
    'employee_invitation_hours' => 72,

    /*
    | Precise clock-in and clock-out locations are deleted this many days after capture.
    | The 30-day default is subject to company and legal review. Values outside
    | 1 to 3650 stop the cleanup instead of deleting records.
    */
    'gps_location_retention_days' => env('HRMS_GPS_RETENTION_DAYS', 30),

    /*
    | Browser map tiles. The default is the public OpenStreetMap server.
    | A future provider belongs here only after its usage policy is approved.
    | This value is not read from the environment and does not use an API key.
    | Tile requests still reveal the viewed area to the host in the template.
    */
    'map_tiles' => [
        'url_template' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    ],

];
