<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

/**
 * Decides whether a test process may touch a database. It inspects configuration
 * values only and never opens a connection.
 */
final class TestDatabaseGuard
{
    public const LIVE_DATABASE = 'meetara_hrms';

    public const MEMORY_DATABASE = ':memory:';

    /**
     * Disposable MySQL databases that an explicit integration configuration may use.
     * A name is allowed only when it appears here. The live database is rejected
     * even if it is later added to this list.
     *
     * @var list<string>
     */
    public const MYSQL_ALLOWLIST = [
        'meetara_hrms_decision_20261020',
        'meetara_hrms_integrity_20261020',
        'meetara_hrms_invite_20261020',
        'meetara_hrms_offer_20261020',
        'meetara_hrms_preflight_20261020180530',
        'meetara_hrms_stage28_20261020',
        'meetara_hrms_stage37_20261020',
    ];

    public static function mysqlIntegrationRequested(): bool
    {
        return defined('HRMS_MYSQL_INTEGRATION') && (string) HRMS_MYSQL_INTEGRATION === '1';
    }

    public static function forceStandardEnvironment(): void
    {
        self::clearDatabaseUrls();
        self::setVariable('DB_CONNECTION', 'sqlite');
        self::setVariable('DB_DATABASE', self::MEMORY_DATABASE);
    }

    public static function assertStandardEnvironment(): void
    {
        $violation = self::standardViolation(self::currentEnvironment(), self::configIsCached());

        if ($violation !== null) {
            throw new RuntimeException($violation);
        }
    }

    public static function assertMysqlIntegrationEnvironment(): void
    {
        $violation = self::mysqlViolation(self::currentEnvironment(), self::configIsCached());

        if ($violation !== null) {
            throw new RuntimeException($violation);
        }

        self::clearDatabaseUrls();
        self::setVariable('DB_CONNECTION', 'mysql');
        self::setVariable('DB_DATABASE', (string) self::environmentValue('DB_DATABASE'));
    }

    public static function assertBootedConfiguration(Application $app, bool $mysqlIntegration): void
    {
        if ($app->configurationIsCached()) {
            throw new RuntimeException(self::cachedConfigMessage());
        }

        $default = (string) $app['config']->get('database.default');
        $connection = $app['config']->get('database.connections.'.$default);
        $connection = is_array($connection) ? $connection : [];
        $environment = [
            'connection' => $default,
            'database' => isset($connection['database']) ? (string) $connection['database'] : null,
            'db_url' => isset($connection['url']) ? (string) $connection['url'] : null,
            'database_url' => self::environmentValue('DATABASE_URL'),
        ];
        $violation = $mysqlIntegration
            ? self::mysqlViolation($environment, false)
            : self::standardViolation($environment, false);

        if ($violation !== null) {
            throw new RuntimeException($violation);
        }
    }

    /**
     * @return 'run'|'skip'
     */
    public static function integrationDecision(string $expectedDatabase): string
    {
        if ($expectedDatabase === self::LIVE_DATABASE || ! in_array($expectedDatabase, self::MYSQL_ALLOWLIST, true)) {
            throw new RuntimeException('Refusing to run a MySQL integration test because its expected database is not an explicit disposable database.');
        }

        $default = (string) config('database.default');
        $configured = config('database.connections.'.$default);
        $database = is_array($configured) && isset($configured['database']) ? (string) $configured['database'] : '';
        $url = is_array($configured) ? ($configured['url'] ?? null) : null;

        if (self::present($url) || self::present(self::environmentValue('DATABASE_URL')) || self::present(self::environmentValue('DB_URL'))) {
            throw new RuntimeException(self::urlMessage());
        }

        if ($database === self::LIVE_DATABASE) {
            throw new RuntimeException(self::liveDatabaseMessage());
        }

        if (self::mysqlIntegrationRequested()) {
            if ($default === 'mysql' && $database === $expectedDatabase) {
                return 'run';
            }

            throw new RuntimeException('Refusing to run a MySQL integration test because the configured database is not the explicit disposable database required by that test.');
        }

        if ($default === 'sqlite' && $database === self::MEMORY_DATABASE) {
            return 'skip';
        }

        throw new RuntimeException('Refusing to run a MySQL integration test because the standard PHPUnit suite is not using sqlite :memory:.');
    }

    /**
     * @param  array{connection: ?string, database: ?string, db_url: ?string, database_url: ?string}  $environment
     */
    public static function standardViolation(array $environment, bool $configCached): ?string
    {
        if ($configCached) {
            return self::cachedConfigMessage();
        }

        if (self::present($environment['db_url'] ?? null) || self::present($environment['database_url'] ?? null)) {
            return self::urlMessage();
        }

        if (($environment['database'] ?? null) === self::LIVE_DATABASE) {
            return self::liveDatabaseMessage();
        }

        if (($environment['connection'] ?? null) !== 'sqlite') {
            return 'Refusing to run the standard PHPUnit suite because the database connection is not sqlite. MySQL, MariaDB, PostgreSQL, SQL Server, and any other connection are rejected. Unset DB_CONNECTION in the shell and run vendor/bin/phpunit with phpunit.xml. Do not bypass this guard.';
        }

        if (($environment['database'] ?? null) !== self::MEMORY_DATABASE) {
            return 'Refusing to run the standard PHPUnit suite because the database is not sqlite :memory:. File-based SQLite databases and every other database name are rejected. Do not bypass this guard.';
        }

        return null;
    }

    /**
     * @param  array{connection: ?string, database: ?string, db_url: ?string, database_url: ?string}  $environment
     */
    public static function mysqlViolation(array $environment, bool $configCached): ?string
    {
        if ($configCached) {
            return self::cachedConfigMessage();
        }

        if (self::present($environment['db_url'] ?? null) || self::present($environment['database_url'] ?? null)) {
            return self::urlMessage();
        }

        $database = $environment['database'] ?? null;

        if ($database === self::LIVE_DATABASE) {
            return self::liveDatabaseMessage();
        }

        if (($environment['connection'] ?? null) !== 'mysql' || ! is_string($database) || ! in_array($database, self::MYSQL_ALLOWLIST, true)) {
            return 'Refusing to run the MySQL integration suite because DB_CONNECTION must be mysql and DB_DATABASE must be one explicit disposable name on the integration allowlist. The live database is prohibited. Do not bypass this guard.';
        }

        return null;
    }

    /**
     * @return array{connection: ?string, database: ?string, db_url: ?string, database_url: ?string}
     */
    public static function currentEnvironment(): array
    {
        return [
            'connection' => self::environmentValue('DB_CONNECTION'),
            'database' => self::environmentValue('DB_DATABASE'),
            'db_url' => self::environmentValue('DB_URL'),
            'database_url' => self::environmentValue('DATABASE_URL'),
        ];
    }

    public static function configIsCached(): bool
    {
        $configured = self::environmentValue('APP_CONFIG_CACHE');
        $path = is_string($configured) && $configured !== ''
            ? $configured
            : dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';

        return is_file($path);
    }

    private static function clearDatabaseUrls(): void
    {
        foreach (['DB_URL', 'DATABASE_URL'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    private static function setVariable(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private static function environmentValue(string $name): ?string
    {
        if (array_key_exists($name, $_SERVER) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }

        if (array_key_exists($name, $_ENV) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function present(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private static function liveDatabaseMessage(): string
    {
        return 'Refusing to run tests because the live database meetara_hrms is prohibited. Do not point PHPUnit, Playwright, or a seed script at it, and do not bypass this guard.';
    }

    private static function urlMessage(): string
    {
        return 'Refusing to run tests because DB_URL or DATABASE_URL is set. Unset both. A database URL can replace the configured database and may contain credentials. Do not bypass this guard.';
    }

    private static function cachedConfigMessage(): string
    {
        return 'Refusing to run tests because cached application configuration is present. Run php artisan optimize:clear, then run the tests again. Cached configuration can ignore the test database settings. Do not bypass this guard.';
    }
}
