<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Tests\Support\TestDatabaseGuard;

try {
    TestDatabaseGuard::forceStandardEnvironment();
    TestDatabaseGuard::assertStandardEnvironment();
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
