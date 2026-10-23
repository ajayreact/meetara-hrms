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
            fn (string $proxy): bool => $proxy !== '' && $proxy !== '*' && ! self::proxyIsLoopbackOrPrivate($proxy)
        ));
    }

    /**
     * A proxy running on this computer, or elsewhere on a private LAN, is not
     * a public office network. Trusting that peer would let the browser choose
     * the address in X-Forwarded-For.
     */
    private static function proxyIsLoopbackOrPrivate(string $proxy): bool
    {
        try {
            if (str_contains($proxy, '/')) {
                NetworkAddress::normalizeCidr($proxy);

                return false;
            }
        } catch (\InvalidArgumentException) {
            return true;
        }

        return NetworkAddress::isForbidden($proxy);
    }

    private static function overridden(mixed $override): bool
    {
        return $override !== null && $override !== '';
    }
}
