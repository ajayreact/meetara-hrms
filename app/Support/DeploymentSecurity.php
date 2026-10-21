<?php

namespace App\Support;

class DeploymentSecurity
{
    public static function secureSessionCookie(?string $environment, mixed $override): bool
    {
        if (self::overridden($override)) {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }

        return $environment === 'production';
    }

    public static function encryptSessions(?string $environment, mixed $override): bool
    {
        if (self::overridden($override)) {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }

        return $environment === 'production';
    }

    public static function requiresHttps(?string $environment): bool
    {
        return $environment === 'production';
    }

    /**
     * A wildcard is never trusted. An empty value means the browser connects directly.
     *
     * @return list<string>
     */
    public static function trustedProxies(mixed $configured): array
    {
        if (! is_string($configured)) {
            return [];
        }

        $configured = trim($configured);

        if ($configured === '' || $configured === '*') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $configured)),
            fn (string $proxy): bool => $proxy !== '' && $proxy !== '*'
        ));
    }

    private static function overridden(mixed $override): bool
    {
        return $override !== null && $override !== '';
    }
}
