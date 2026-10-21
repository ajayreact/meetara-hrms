<?php

namespace Tests\Unit;

use App\Support\NetworkAddress;
use PHPUnit\Framework\TestCase;

class NetworkAddressTest extends TestCase
{
    public function test_it_matches_ipv4_ipv6_and_cidr_ranges(): void
    {
        $this->assertSame('203.0.113.10/32', NetworkAddress::normalizeCidr('203.0.113.10'));
        $this->assertSame('203.0.113.0/24', NetworkAddress::normalizeCidr('203.0.113.50/24'));
        $this->assertSame('2001:db8::/32', NetworkAddress::normalizeCidr('2001:db8::1/32'));
        $this->assertTrue(NetworkAddress::matches('203.0.113.50', ['203.0.113.0/24']));
        $this->assertTrue(NetworkAddress::matches('2001:db8::10', ['2001:db8::/32']));
        $this->assertFalse(NetworkAddress::matches('198.51.100.5', ['203.0.113.0/24']));
        $this->assertFalse(NetworkAddress::matches('192.168.1.10', ['203.0.113.0/24']));
    }

    public function test_it_rejects_private_and_oversized_ranges(): void
    {
        foreach (['192.168.1.10', '10.0.0.0/16', '127.0.0.1', '169.254.1.1', '172.16.0.0/24', '0.0.0.0/0', 'fc00::/7'] as $value) {
            try {
                NetworkAddress::normalizeCidr($value);
                $this->fail($value.' was accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
