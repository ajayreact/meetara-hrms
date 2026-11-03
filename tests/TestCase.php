<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    protected function isolatePrivateDisk(): void
    {
        $root = storage_path('framework/testing/private-disk');

        if (! is_dir($root) && ! mkdir($root, 0777, true) && ! is_dir($root)) {
            throw new \RuntimeException('Unable to create the temporary storage disk.');
        }

        config(['filesystems.disks.local.root' => $root]);
        Storage::purge('local');
    }

    public function createApplication(): Application
    {
        if (TestDatabaseGuard::mysqlIntegrationRequested()) {
            TestDatabaseGuard::assertMysqlIntegrationEnvironment();
        } else {
            TestDatabaseGuard::forceStandardEnvironment();
            TestDatabaseGuard::assertStandardEnvironment();
        }

        $app = parent::createApplication();
        TestDatabaseGuard::assertBootedConfiguration($app, TestDatabaseGuard::mysqlIntegrationRequested());

        return $app;
    }
}
