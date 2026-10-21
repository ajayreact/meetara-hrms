<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

class ClientAddress
{
    /**
     * @return array{ok: bool, ip: ?string, reason: string}
     */
    public function observe(Request $request): array
    {
        if (config('attendance.trusts_all_proxies') === true) {
            return $this->reject('Trusted proxy configuration is invalid.');
        }

        $configured = config('attendance.trusted_proxies', []);
        $proxies = is_array($configured) ? $configured : [];

        if ($this->forwardedHeaderPresent($request) && ! $this->peerIsTrusted($request, $proxies)) {
            return $this->reject('A forwarded address was received from an untrusted peer.');
        }

        $ip = $request->ip();

        if (! is_string($ip) || $ip === '') {
            return $this->reject('The client address is missing.');
        }

        try {
            $normalized = NetworkAddress::normalizeIp($ip);
        } catch (\InvalidArgumentException) {
            return $this->reject('The client address is invalid.');
        }

        return ['ok' => true, 'ip' => $normalized, 'reason' => ''];
    }

    /**
     * @param  list<string>  $proxies
     */
    private function peerIsTrusted(Request $request, array $proxies): bool
    {
        $peer = $request->server->get('REMOTE_ADDR');

        if (! is_string($peer) || $proxies === []) {
            return false;
        }

        return IpUtils::checkIp($peer, $proxies);
    }

    private function forwardedHeaderPresent(Request $request): bool
    {
        foreach (['X-Forwarded-For', 'Forwarded', 'X-Real-IP'] as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ok: bool, ip: ?string, reason: string}
     */
    private function reject(string $reason): array
    {
        Log::warning('Office network verification could not determine the client address.', ['reason' => $reason]);

        return ['ok' => false, 'ip' => null, 'reason' => $reason];
    }
}
