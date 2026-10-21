<?php

namespace App\Support;

class GeoDistance
{
    /**
     * Great-circle distance in meters. The result is a calculation, not proof of presence.
     */
    public static function meters(float $latitude, float $longitude, float $otherLatitude, float $otherLongitude): float
    {
        $earth = 6371000.0;
        $latDelta = deg2rad($otherLatitude - $latitude);
        $lngDelta = deg2rad($otherLongitude - $longitude);
        $lat1 = deg2rad($latitude);
        $lat2 = deg2rad($otherLatitude);
        $a = sin($latDelta / 2) ** 2 + cos($lat1) * cos($lat2) * sin($lngDelta / 2) ** 2;

        return 2 * $earth * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }
}
