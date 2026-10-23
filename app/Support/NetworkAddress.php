<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

class NetworkAddress
{
    /**
     * @throws \InvalidArgumentException
     */
    public static function normalizeCidr(string $value): string
    {
        $value = trim($value);

        if ($value === '' || str_contains($value, '%') || substr_count($value, '/') > 1) {
            throw new \InvalidArgumentException('Enter a public IP address or CIDR range.');
        }

        if (! str_contains($value, '/')) {
            $ip = self::normalizeIp($value);
            $value = $ip.'/'.(str_contains($ip, ':') ? '128' : '32');
        }

        [$address, $prefix] = explode('/', $value, 2);

        if (! ctype_digit($prefix)) {
            throw new \InvalidArgumentException('Enter a public IP address or CIDR range.');
        }

        $ip = self::normalizeIp($address);
        $bits = str_contains($ip, ':') ? 128 : 32;
        $length = (int) $prefix;
        $minimum = $bits === 32 ? 16 : 32;

        if ($length < $minimum || $length > $bits) {
            throw new \InvalidArgumentException('Enter a public IP address or CIDR range.');
        }

        $network = self::networkBase($ip, $length);

        if (self::rangeIsForbidden($network, $length)) {
            throw new \InvalidArgumentException('Enter a public IP address or CIDR range.');
        }

        return $network.'/'.$length;
    }

    public static function normalizeIp(string $value): string
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Enter a public IP address or CIDR range.');
        }

        $packed = inet_pton($value);

        if ($packed === false) {
            throw new \InvalidArgumentException('Enter a public IP address or CIDR range.');
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $mapped = inet_ntop(substr($packed, 12));

            return $mapped === false ? throw new \InvalidArgumentException('Enter a public IP address or CIDR range.') : $mapped;
        }

        $normalized = inet_ntop($packed);

        if ($normalized === false) {
            throw new \InvalidArgumentException('Enter a public IP address or CIDR range.');
        }

        return strtolower($normalized);
    }

    public static function isForbidden(string $ip): bool
    {
        try {
            $ip = self::normalizeIp($ip);
        } catch (\InvalidArgumentException) {
            return true;
        }

        $rules = str_contains($ip, ':')
            ? ['::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8']
            : ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/3'];

        foreach ($rules as $rule) {
            if (IpUtils::checkIp($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $cidrs
     */
    public static function matches(string $ip, array $cidrs): bool
    {
        return self::denialReason($ip, $cidrs) === null;
    }

    /**
     * @param  list<string>  $cidrs
     */
    public static function denialReason(string $ip, array $cidrs): ?string
    {
        if (self::isForbidden($ip)) {
            return 'address_not_public';
        }

        try {
            $ip = self::normalizeIp($ip);
        } catch (\InvalidArgumentException) {
            return 'address_invalid';
        }

        if ($cidrs === []) {
            return 'no_approved_network';
        }

        foreach ($cidrs as $cidr) {
            if (! is_string($cidr)) {
                continue;
            }

            try {
                $normalized = self::normalizeCidr($cidr);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (IpUtils::checkIp($ip, $normalized)) {
                return null;
            }
        }

        return 'address_not_approved';
    }

    private static function networkBase(string $ip, int $prefix): string
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            throw new \InvalidArgumentException('Enter a public IP address or CIDR range.');
        }

        $bits = strlen($packed) * 8;
        $bytes = array_values(unpack('C*', $packed) ?: []);

        for ($index = 0; $index < count($bytes); $index++) {
            $start = $index * 8;
            if ($prefix <= $start) {
                $bytes[$index] = 0;
            } elseif ($prefix < $start + 8) {
                $keep = $prefix - $start;
                $bytes[$index] = $bytes[$index] & (0xFF << (8 - $keep));
            }
        }

        $masked = pack('C*', ...$bytes);
        $normalized = inet_ntop($masked);

        if ($normalized === false || strlen($masked) * 8 !== $bits) {
            throw new \InvalidArgumentException('Enter a public IP address or CIDR range.');
        }

        return strtolower($normalized);
    }

    private static function rangeIsForbidden(string $network, int $prefix): bool
    {
        if (self::isForbidden($network)) {
            return true;
        }

        $packed = inet_pton($network);

        if ($packed === false) {
            return true;
        }

        $bytes = array_values(unpack('C*', $packed) ?: []);
        $bits = count($bytes) * 8;

        for ($index = 0; $index < count($bytes); $index++) {
            $start = $index * 8;
            if ($prefix <= $start) {
                $bytes[$index] = 0xFF;
            } elseif ($prefix < $start + 8) {
                $keep = $prefix - $start;
                $bytes[$index] = $bytes[$index] | (0xFF >> $keep);
            }
        }

        $last = inet_ntop(pack('C*', ...$bytes));

        return $last === false || strlen(pack('C*', ...$bytes)) * 8 !== $bits || self::isForbidden($last);
    }
}
