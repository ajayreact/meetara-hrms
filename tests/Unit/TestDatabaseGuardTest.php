<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    public function test_sqlite_memory_is_accepted_for_the_standard_suite(): void
    {
        $this->assertNull(TestDatabaseGuard::standardViolation([
            'connection' => 'sqlite',
            'database' => ':memory:',
            'db_url' => null,
            'database_url' => null,
        ], false));
    }

    public function test_mysql_is_rejected_for_the_standard_suite(): void
    {
        $message = TestDatabaseGuard::standardViolation([
            'connection' => 'mysql',
            'database' => ':memory:',
            'db_url' => null,
            'database_url' => null,
        ], false);

        $this->assertNotNull($message);
        $this->assertStringContainsString('not sqlite', $message);
    }

    public function test_file_sqlite_is_rejected_for_the_standard_suite(): void
    {
        $message = TestDatabaseGuard::standardViolation([
            'connection' => 'sqlite',
            'database' => 'database/database.sqlite',
            'db_url' => null,
            'database_url' => null,
        ], false);

        $this->assertNotNull($message);
        $this->assertStringContainsString(':memory:', $message);
        $this->assertStringContainsString('File-based SQLite', $message);
    }

    public function test_live_database_name_is_rejected_without_echoing_a_url(): void
    {
        $secret = 'mysql://root:super-secret-password@127.0.0.1/meetara_hrms';
        $message = TestDatabaseGuard::standardViolation([
            'connection' => 'sqlite',
            'database' => 'meetara_hrms',
            'db_url' => null,
            'database_url' => null,
        ], false);

        $this->assertNotNull($message);
        $this->assertStringContainsString('meetara_hrms', $message);
        $this->assertStringNotContainsString($secret, $message);
        $this->assertStringNotContainsString('super-secret-password', $message);

        $urlMessage = TestDatabaseGuard::standardViolation([
            'connection' => 'sqlite',
            'database' => ':memory:',
            'db_url' => $secret,
            'database_url' => $secret,
        ], false);

        $this->assertNotNull($urlMessage);
        $this->assertStringContainsString('DB_URL or DATABASE_URL', $urlMessage);
        $this->assertStringNotContainsString('super-secret-password', $urlMessage);
        $this->assertStringNotContainsString('mysql://', $urlMessage);
    }

    public function test_database_url_cannot_authorize_the_mysql_integration_suite(): void
    {
        $message = TestDatabaseGuard::mysqlViolation([
            'connection' => 'mysql',
            'database' => 'meetara_hrms_stage37_20261020',
            'db_url' => null,
            'database_url' => 'mysql://example:secret@127.0.0.1/meetara_hrms',
        ], false);

        $this->assertNotNull($message);
        $this->assertStringContainsString('DB_URL or DATABASE_URL', $message);
        $this->assertStringNotContainsString('secret', $message);
    }

    public function test_mysql_integration_rejects_the_live_database_and_unknown_names(): void
    {
        $live = TestDatabaseGuard::mysqlViolation([
            'connection' => 'mysql',
            'database' => 'meetara_hrms',
            'db_url' => null,
            'database_url' => null,
        ], false);
        $unknown = TestDatabaseGuard::mysqlViolation([
            'connection' => 'mysql',
            'database' => 'meetara_hrms_not_allowlisted',
            'db_url' => null,
            'database_url' => null,
        ], false);
        $accepted = TestDatabaseGuard::mysqlViolation([
            'connection' => 'mysql',
            'database' => 'meetara_hrms_stage37_20261020',
            'db_url' => null,
            'database_url' => null,
        ], false);

        $this->assertNotNull($live);
        $this->assertStringContainsString('meetara_hrms is prohibited', $live);
        $this->assertNotNull($unknown);
        $this->assertStringContainsString('allowlist', $unknown);
        $this->assertNull($accepted);
    }

    public function test_this_process_was_forced_to_sqlite_memory(): void
    {
        $this->assertFalse(TestDatabaseGuard::mysqlIntegrationRequested());
        $this->assertSame('sqlite', $_SERVER['DB_CONNECTION'] ?? null);
        $this->assertSame(':memory:', $_SERVER['DB_DATABASE'] ?? null);
        $this->assertSame('sqlite', $_ENV['DB_CONNECTION'] ?? null);
        $this->assertSame(':memory:', $_ENV['DB_DATABASE'] ?? null);
        $this->assertNull(TestDatabaseGuard::standardViolation(TestDatabaseGuard::currentEnvironment(), TestDatabaseGuard::configIsCached()));
    }
}
