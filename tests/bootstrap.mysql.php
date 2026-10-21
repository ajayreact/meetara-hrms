<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Tests\Support\TestDatabaseGuard;

try {
    TestDatabaseGuard::assertMysqlIntegrationEnvironment();
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
