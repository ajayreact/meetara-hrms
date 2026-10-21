<?php

declare(strict_types=1);

/**
 * Read-only migration inspection. This script does not bootstrap Laravel and
 * does not call migration methods. Without --read it opens no connection.
 *
 * Usage:
 *   php scripts/inspect-migration-state.php meetara_hrms_inspect_YYYYMMDD
 *   php scripts/inspect-migration-state.php meetara_hrms_inspect_YYYYMMDD --read
 */

$project = dirname(__DIR__);
require $project.'/vendor/autoload.php';

use App\Support\MigrationInspection;
use App\Support\PdoMigrationInspectionReader;

$inspection = new MigrationInspection;
$expected = $argv[1] ?? null;
$read = ($argv[2] ?? '') === '--read';

$refusal = $inspection->preflight(
    is_string($expected) ? $expected : null,
    getenv('HRMS_INSPECT_DRIVER') === false ? '' : (string) getenv('HRMS_INSPECT_DRIVER'),
    getenv('DB_DATABASE') === false ? null : (string) getenv('DB_DATABASE'),
    getenv('DB_URL') === false ? null : (string) getenv('DB_URL'),
    getenv('DATABASE_URL') === false ? null : (string) getenv('DATABASE_URL'),
    is_file($project.'/bootstrap/cache/config.php'),
);

if ($refusal !== null) {
    fwrite(STDERR, $refusal.PHP_EOL);
    exit(2);
}

if (! $read) {
    fwrite(STDOUT, 'Preflight accepted '.$expected.'. No connection was opened. A later authorized run must pass --read.'.PHP_EOL);
    exit(0);
}

$host = getenv('HRMS_INSPECT_HOST');
$port = getenv('HRMS_INSPECT_PORT');
$username = getenv('HRMS_INSPECT_USERNAME');
$password = getenv('HRMS_INSPECT_PASSWORD');

if (! is_string($host) || $host === '' || ! is_string($port) || $port === '' || ! is_string($username) || $username === '' || ! is_string($password)) {
    fwrite(STDERR, 'Refusing to connect because HRMS_INSPECT_HOST, HRMS_INSPECT_PORT, HRMS_INSPECT_USERNAME, and HRMS_INSPECT_PASSWORD must be set in the process environment.'.PHP_EOL);
    exit(2);
}

try {
    $pdo = new PDO(
        'mysql:host='.$host.';port='.$port.';dbname='.$expected,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ],
    );
} catch (Throwable) {
    fwrite(STDERR, 'MySQL connection failed.'.PHP_EOL);
    exit(5);
}

try {
    $outcome = $inspection->read(new PdoMigrationInspectionReader($pdo), (string) $expected);
} catch (Throwable) {
    fwrite(STDERR, 'The read-only inspection query failed. No change was requested.'.PHP_EOL);
    exit(6);
}

foreach ($outcome['trace'] as $line) {
    echo 'trace='.$line.PHP_EOL;
}

if ($outcome['status'] !== 'ok') {
    fwrite(STDERR, ($outcome['message'] ?? 'The inspection stopped.').PHP_EOL);
    exit(4);
}

echo 'database='.$expected.PHP_EOL;

foreach ($outcome['lines'] as $line) {
    echo $inspection->redactTrace([$line])[0].PHP_EOL;
}
