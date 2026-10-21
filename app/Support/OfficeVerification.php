<?php

namespace App\Support;

use App\Models\Office;
use Illuminate\Support\Facades\Schema;

class OfficeVerification
{
    public const MIN_RADIUS_METERS = 25;

    public const MAX_RADIUS_METERS = 2000;

    public const DEFAULT_RADIUS_METERS = 150;

    public static function columnsAvailable(): bool
    {
        return Schema::hasTable('locations')
            && Schema::hasColumn('locations', 'latitude')
            && Schema::hasColumn('locations', 'longitude')
            && Schema::hasColumn('locations', 'verification_radius_meters')
            && Schema::hasColumn('locations', 'gps_verification_enabled');
    }

    /**
     * @return array{
     *     locationConfigured: bool,
     *     networkConfigured: bool,
     *     gpsEnabled: bool,
     *     attendanceReady: bool,
     *     methods: list<string>,
     *     message: string
     * }
     */
    public static function describe(Office $office): array
    {
        $networkConfigured = self::networkCount($office) > 0;
        $locationConfigured = self::coordinatesValid($office->latitude, $office->longitude);
        $gpsEnabled = self::columnsAvailable() && (bool) $office->gps_verification_enabled;
        $radiusValid = self::radiusValid($office->verification_radius_meters);
        $active = (string) $office->status === 'active';
        $blockers = [];

        if (! $active) {
            $blockers[] = 'This office is inactive, so clock-in is blocked.';
        }

        if (! $networkConfigured) {
            $blockers[] = 'No approved public network is configured, so clock-in is blocked.';
        }

        if ($gpsEnabled && ! $locationConfigured) {
            $blockers[] = 'GPS verification is enabled, but this office has no saved coordinates, so clock-in is blocked.';
        }

        if ($gpsEnabled && $locationConfigured && ! $radiusValid) {
            $blockers[] = 'The location radius is outside the allowed range, so clock-in is blocked.';
        }

        $ready = $blockers === [];
        $methods = [];

        if ($networkConfigured) {
            $methods[] = 'Approved public network';
        }

        if ($gpsEnabled && $locationConfigured && $radiusValid) {
            $methods[] = 'GPS location, in addition to the approved network';
        }

        if ($ready && $gpsEnabled) {
            $message = 'Clock-in is allowed only from an approved public network and a browser location inside the saved radius. GPS alone is not accepted as proof of presence.';
        } elseif ($ready) {
            $message = 'Clock-in is allowed only from an approved public network. GPS verification is off.';
        } else {
            $message = implode(' ', $blockers);
        }

        return [
            'locationConfigured' => $locationConfigured,
            'networkConfigured' => $networkConfigured,
            'gpsEnabled' => $gpsEnabled,
            'attendanceReady' => $ready,
            'methods' => $methods,
            'message' => $message,
        ];
    }

    public static function coordinatesValid(mixed $latitude, mixed $longitude): bool
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        return $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180;
    }

    public static function radiusValid(mixed $radius): bool
    {
        if (is_string($radius) && ctype_digit($radius)) {
            $radius = (int) $radius;
        }

        if (is_float($radius)) {
            if (floor($radius) !== $radius) {
                return false;
            }

            $radius = (int) $radius;
        }

        if (! is_int($radius)) {
            return false;
        }

        return $radius >= self::MIN_RADIUS_METERS && $radius <= self::MAX_RADIUS_METERS;
    }

    private static function networkCount(Office $office): int
    {
        if ($office->relationLoaded('networks')) {
            return $office->networks->count();
        }

        return $office->networks()->count();
    }
}
