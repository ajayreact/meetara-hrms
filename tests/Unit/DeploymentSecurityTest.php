<?php

namespace Tests\Unit;

use App\Support\DeploymentSecurity;
use PHPUnit\Framework\TestCase;

class DeploymentSecurityTest extends TestCase
{
    public function test_production_defaults_are_strict_and_local_http_stays_usable(): void
    {
        $this->assertTrue(DeploymentSecurity::requiresHttps('production'));
        $this->assertTrue(DeploymentSecurity::secureSessionCookie('production', null));
        $this->assertTrue(DeploymentSecurity::encryptSessions('production', null));

        $this->assertFalse(DeploymentSecurity::requiresHttps('local'));
        $this->assertFalse(DeploymentSecurity::secureSessionCookie('local', null));
        $this->assertFalse(DeploymentSecurity::encryptSessions('local', null));
        $this->assertFalse(DeploymentSecurity::requiresHttps('testing'));

        $this->assertFalse(DeploymentSecurity::secureSessionCookie('production', 'false'));
        $this->assertTrue(DeploymentSecurity::encryptSessions('local', 'true'));
    }

    public function test_a_wildcard_proxy_is_never_trusted(): void
    {
        $this->assertSame([], DeploymentSecurity::trustedProxies(null));
        $this->assertSame([], DeploymentSecurity::trustedProxies(''));
        $this->assertSame([], DeploymentSecurity::trustedProxies('*'));
        $this->assertSame([], DeploymentSecurity::trustedProxies(' * '));
        $this->assertSame(['198.51.100.8'], DeploymentSecurity::trustedProxies('198.51.100.8, *'));
        $this->assertSame(['198.51.100.8'], DeploymentSecurity::trustedProxies('127.0.0.1, 192.168.0.174, 198.51.100.8'));
        $this->assertSame([], DeploymentSecurity::trustedProxies('127.0.0.1'));
        $this->assertSame([], DeploymentSecurity::trustedProxies('192.168.0.174'));
    }
}
